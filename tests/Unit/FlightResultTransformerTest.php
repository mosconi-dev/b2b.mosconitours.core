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
