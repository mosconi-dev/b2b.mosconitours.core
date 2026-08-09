<?php

namespace Tests\Unit;

use App\Services\TboAir\FlightResultTransformer;
use PHPUnit\Framework\TestCase;

class FlightResultTransformerTest extends TestCase
{
    private function fixture(string $name): array
    {
        return json_decode(file_get_contents(__DIR__.'/../Fixtures/tboair/'.$name), true);
    }

    public function test_transforms_nested_response_envelope(): void
    {
        $offers = (new FlightResultTransformer)->transform($this->fixture('search-oneway.json'));

        $this->assertCount(2, $offers);

        $first = $offers[0];
        $this->assertSame('OB1', $first->resultIndex);
        $this->assertSame('PR', $first->airlineCode);
        $this->assertSame('Philippine Airlines', $first->airlineName);
        $this->assertSame(['PR2782'], $first->flightNumbers);
        $this->assertSame('MNL', $first->departure['code']);
        $this->assertSame('MPH', $first->arrival['code']);
        $this->assertSame(0, $first->stops);
        $this->assertSame(75, $first->duration);
        $this->assertTrue($first->isRefundable);
        $this->assertSame('Economy', $first->cabin);
        $this->assertSame(4300.0, $first->price['offeredFare']);
        $this->assertSame('PHP', $first->price['currency']);
    }

    public function test_envelope_agnostic_same_output(): void
    {
        $transformer = new FlightResultTransformer;

        $nested = $transformer->transform($this->fixture('search-oneway.json'))[0];
        $flat = $transformer->transform($this->fixture('search-flat.json'))[0];

        $this->assertEquals($nested->toArray(), $flat->toArray());
    }

    public function test_one_stop_offer_computes_stops_and_layover(): void
    {
        $offers = (new FlightResultTransformer)->transform($this->fixture('search-oneway.json'));

        $second = $offers[1];
        $this->assertSame(1, $second->stops);
        $this->assertSame(170, $second->duration);
        $this->assertSame(90, $second->trips[0]['segments'][0]['layoverAfter']);
        $this->assertNull($second->trips[0]['segments'][1]['layoverAfter']);
    }

    public function test_missing_keys_do_not_fatal(): void
    {
        $offers = (new FlightResultTransformer)->transform(['Results' => [['ResultIndex' => 'X']]]);

        $this->assertCount(1, $offers);
        $this->assertSame('X', $offers[0]->resultIndex);
        $this->assertSame('', $offers[0]->airlineCode);
        $this->assertSame(0.0, $offers[0]->price['offeredFare']);
        $this->assertSame([], $offers[0]->trips);
    }

    /**
     * TBO sometimes blanks the headline Fare block — no OfferedFare/PublishedFare
     * key and Tax reset to 0 — while FareBreakdown still holds the real numbers.
     * Shape taken from a live search response that priced every offer at 0.
     */
    public function test_falls_back_to_fare_breakdown_when_headline_fare_is_blank(): void
    {
        $offers = (new FlightResultTransformer)->transform(['Results' => [[
            'ResultIndex' => 'OB1',
            'Fare' => ['Currency' => 'PHP', 'BaseFare' => 11852.16, 'Tax' => 0, 'YQTax' => 2054.69],
            'FareBreakdown' => [
                ['PassengerType' => 1, 'PassengerCount' => 1, 'BaseFare' => 11852.16, 'Tax' => 2976.26],
            ],
        ]]]);

        $this->assertSame(14828.42, $offers[0]->price['offeredFare']);
    }

    public function test_fare_breakdown_rows_are_group_totals(): void
    {
        $offers = (new FlightResultTransformer)->transform(['Results' => [[
            'ResultIndex' => 'OB2',
            'Fare' => ['Currency' => 'PHP'],
            'FareBreakdown' => [
                ['PassengerType' => 1, 'PassengerCount' => 3, 'BaseFare' => 30000.0, 'Tax' => 10183.02],
                ['PassengerType' => 2, 'PassengerCount' => 1, 'BaseFare' => 8000.0, 'Tax' => 2000.0],
            ],
        ]]]);

        // Rows already cover their whole passenger group, so they are summed, not multiplied.
        $this->assertEqualsWithDelta(50183.02, $offers[0]->price['offeredFare'], 0.01);
    }

    public function test_zero_offered_fare_falls_back_to_published(): void
    {
        $offers = (new FlightResultTransformer)->transform(['Results' => [[
            'ResultIndex' => 'OB3',
            'Fare' => ['Currency' => 'PHP', 'OfferedFare' => 0, 'PublishedFare' => 4300.0],
        ]]]);

        $this->assertSame(4300.0, $offers[0]->price['offeredFare']);
    }

    /**
     * @param  array<int, array{0: ?string, 1: ?string}>  $legs  [Baggage, CabinBaggage] per leg
     * @return array<string, mixed>
     */
    private function offerWithBaggage(array $legs): array
    {
        return ['Results' => [[
            'ResultIndex' => 'BAG',
            'Fare' => ['Currency' => 'PHP', 'OfferedFare' => 5000],
            'Segments' => [array_map(fn (array $leg): array => [
                'Airline' => ['AirlineCode' => 'PR', 'FlightNumber' => '101'],
                'Origin' => ['Airport' => ['AirportCode' => 'MNL'], 'DepTime' => '2026-07-01T06:00:00'],
                'Destination' => ['Airport' => ['AirportCode' => 'CEB'], 'ArrTime' => '2026-07-01T07:15:00'],
                'Duration' => 75, 'CabinClass' => 2,
                'Baggage' => $leg[0], 'CabinBaggage' => $leg[1],
            ], $legs)],
        ]]];
    }

    public function test_summary_baggage_is_the_lowest_allowance_across_legs(): void
    {
        // A generous first leg followed by a bare LCC connection: the summary must
        // show what the whole itinerary guarantees, not the first leg's 20 KG.
        $offer = (new FlightResultTransformer)->transform($this->offerWithBaggage([
            ['20 KG', '7 KG'],
            ['0 KG', '5 KG'],
        ]))[0];

        $this->assertSame('0 KG', $offer->baggage);
        $this->assertSame('5 KG', $offer->cabinBaggage);

        // Per-leg values stay untouched for the flight-details breakdown.
        $this->assertSame('20 KG', $offer->trips[0]['segments'][0]['baggage']);
        $this->assertSame('0 KG', $offer->trips[0]['segments'][1]['baggage']);
    }

    public function test_baggage_falls_back_to_first_leg_when_units_are_not_comparable(): void
    {
        $transformer = new FlightResultTransformer;

        // Mixed units cannot be ordered, so we repeat the first leg's own wording.
        $mixed = $transformer->transform($this->offerWithBaggage([
            ['2 Piece', '7 KG'],
            ['25 KG', '7 KG'],
        ]))[0];
        $this->assertSame('2 Piece', $mixed->baggage);

        // Unparseable text is passed through rather than dropped.
        $wordy = $transformer->transform($this->offerWithBaggage([
            ['Included', '7 KG'],
            ['20 KG', '7 KG'],
        ]))[0];
        $this->assertSame('Included', $wordy->baggage);

        // Legs that carry nothing are skipped; missing everywhere stays null.
        $partial = $transformer->transform($this->offerWithBaggage([
            [null, ''],
            ['15 KG', null],
        ]))[0];
        $this->assertSame('15 KG', $partial->baggage);
        $this->assertNull($partial->cabinBaggage);
    }

    public function test_groups_round_trip_segments_by_indicator(): void
    {
        $raw = [
            'Results' => [[
                'ResultIndex' => 'RT',
                'Fare' => ['Currency' => 'PHP', 'OfferedFare' => 5000],
                'Segments' => [
                    [
                        'TripIndicator' => 1,
                        'Airline' => ['AirlineCode' => 'PR', 'FlightNumber' => '101'],
                        'Origin' => ['Airport' => ['AirportCode' => 'MNL'], 'DepTime' => '2026-07-01T06:00:00'],
                        'Destination' => ['Airport' => ['AirportCode' => 'MPH'], 'ArrTime' => '2026-07-01T07:15:00'],
                        'Duration' => 75, 'CabinClass' => 2,
                    ],
                    [
                        'TripIndicator' => 2,
                        'Airline' => ['AirlineCode' => 'PR', 'FlightNumber' => '102'],
                        'Origin' => ['Airport' => ['AirportCode' => 'MPH'], 'DepTime' => '2026-07-05T08:00:00'],
                        'Destination' => ['Airport' => ['AirportCode' => 'MNL'], 'ArrTime' => '2026-07-05T09:15:00'],
                        'Duration' => 75, 'CabinClass' => 2,
                    ],
                ],
            ]],
        ];

        $offer = (new FlightResultTransformer)->transform($raw)[0];

        $this->assertCount(2, $offer->trips);
        $this->assertSame('outbound', $offer->trips[0]['direction']);
        $this->assertSame('inbound', $offer->trips[1]['direction']);
        $this->assertSame('MNL', $offer->departure['code']);
        $this->assertSame('MPH', $offer->arrival['code']);
    }
}
