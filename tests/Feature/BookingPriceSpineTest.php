<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\Booking;
use App\Models\BookingPriceLayer;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 2 of pricing: the booking spine can hold a marked-up price, and the supplier is
 * still sent the net one. No markup exists yet — every booking writes net = cost = total.
 */
class BookingPriceSpineTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_booking_carries_net_cost_sell_and_markup(): void
    {
        $booking = Booking::factory()->create([
            'net_amount' => '5000.00',
            'cost_amount' => '5500.00',
            'total_amount' => '5700.00',
            'markup_total' => '700.00',
        ]);

        $booking->refresh();

        $this->assertSame('5000.00', $booking->net_amount);
        $this->assertSame('5500.00', $booking->cost_amount);
        $this->assertSame('5700.00', $booking->total_amount);
        $this->assertSame('700.00', $booking->markup_total);
    }

    public function test_price_layers_read_back_in_level_order(): void
    {
        $booking = Booking::factory()->create();
        $office = Agency::factory()->create();
        $agency = Agency::factory()->create();

        // Inserted out of order on purpose — the relation sorts, the caller does not.
        $booking->priceLayers()->create($this->layer(BookingPriceLayer::AGENCY, $agency, '200.00', '5700.00'));
        $booking->priceLayers()->create($this->layer(BookingPriceLayer::MAIN_OFFICE, $office, '500.00', '5500.00'));

        $layers = $booking->fresh()->priceLayers;

        $this->assertCount(2, $layers);
        $this->assertSame(BookingPriceLayer::MAIN_OFFICE, $layers[0]->level);
        $this->assertSame('500.00', $layers[0]->markup_amount);
        $this->assertSame('Main Office', $layers[0]->levelLabel());
        $this->assertSame(BookingPriceLayer::AGENCY, $layers[1]->level);
        $this->assertSame('Agency', $layers[1]->levelLabel());
    }

    public function test_a_layer_keeps_its_own_copy_of_the_rule(): void
    {
        $booking = Booking::factory()->create();
        $agency = Agency::factory()->create();

        $booking->priceLayers()->create([
            ...$this->layer(BookingPriceLayer::MAIN_OFFICE, $agency, '500.00', '5500.00'),
            // A pointer at a rule that does not exist — deleted since the booking, which
            // is precisely the case the snapshot exists to survive.
            'pricing_rule_id' => 424242,
            'rule_snapshot' => ['calc_type' => 'fixed', 'value' => '500.0000', 'basis' => 'net', 'version' => 3],
        ]);

        $layer = $booking->fresh()->priceLayers->first();

        $this->assertSame('fixed', $layer->rule_snapshot['calc_type']);
        $this->assertSame(3, $layer->rule_snapshot['version']);
        $this->assertSame('500.00', $layer->markup_amount, 'readable without the rule it points at');
    }

    /**
     * A level contributes one rung per matching rule, so several rows at the same level
     * are the normal case now — a base rate and a service fee, both recorded.
     */
    public function test_a_level_may_record_one_rung_per_rule(): void
    {
        $booking = Booking::factory()->create();
        $agency = Agency::factory()->create();

        $booking->priceLayers()->create(
            $this->layer(BookingPriceLayer::AGENCY, $agency, '250.00', '5750.00') + ['pricing_rule_id' => 11],
        );
        $booking->priceLayers()->create(
            $this->layer(BookingPriceLayer::AGENCY, $agency, '100.00', '5850.00') + ['pricing_rule_id' => 12],
        );

        $this->assertCount(2, $booking->fresh()->priceLayers);
    }

    public function test_the_same_rule_cannot_be_recorded_twice_on_one_booking(): void
    {
        $booking = Booking::factory()->create();
        $agency = Agency::factory()->create();

        $booking->priceLayers()->create(
            $this->layer(BookingPriceLayer::MAIN_OFFICE, $agency, '500.00', '5500.00') + ['pricing_rule_id' => 7],
        );

        // The same rule twice at the same level is the engine having run twice — a
        // silent doubling of the margin if the database allowed it.
        $this->expectException(QueryException::class);

        $booking->priceLayers()->create(
            $this->layer(BookingPriceLayer::MAIN_OFFICE, $agency, '500.00', '6000.00') + ['pricing_rule_id' => 7],
        );
    }

    public function test_the_agency_margin_is_the_agency_rung_only(): void
    {
        $booking = Booking::factory()->create();
        $office = Agency::factory()->create();
        $agency = Agency::factory()->create();

        $booking->priceLayers()->create($this->layer(BookingPriceLayer::MAIN_OFFICE, $office, '500.00', '5500.00'));
        $booking->priceLayers()->create($this->layer(BookingPriceLayer::AGENCY, $agency, '200.00', '5700.00'));

        $this->assertSame('200.00', $booking->fresh()->agencyMargin());
    }

    public function test_a_booking_with_no_layers_has_no_agency_margin(): void
    {
        $this->assertSame('0.00', Booking::factory()->create()->agencyMargin());
    }

    public function test_margin_by_agency_and_level_is_one_query(): void
    {
        $office = Agency::factory()->create();
        $agency = Agency::factory()->create();

        foreach (['200.00', '350.00'] as $margin) {
            $booking = Booking::factory()->create();
            $booking->priceLayers()->create($this->layer(BookingPriceLayer::MAIN_OFFICE, $office, '500.00', '5500.00'));
            $booking->priceLayers()->create($this->layer(BookingPriceLayer::AGENCY, $agency, $margin, '5700.00'));
        }

        // The report the whole table exists for.
        $earned = BookingPriceLayer::where('agency_id', $agency->id)
            ->where('level', BookingPriceLayer::AGENCY)
            ->sum('markup_amount');

        $this->assertEquals('550.00', number_format((float) $earned, 2, '.', ''));
    }

    /**
     * @return array<string, mixed>
     */
    private function layer(int $level, Agency $agency, string $markup, string $running): array
    {
        return [
            'level' => $level,
            'agency_id' => $agency->id,
            'rule_snapshot' => ['calc_type' => 'fixed', 'value' => $markup, 'basis' => 'net'],
            'basis_amount' => '5000.00',
            'markup_amount' => $markup,
            'running_total' => $running,
            'created_at' => now(),
        ];
    }
}
