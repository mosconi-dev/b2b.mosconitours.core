<?php

namespace Tests\Feature;

use App\Enums\BookingProduct;
use App\Enums\BookingStatus;
use App\Enums\Supplier;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The `bookings` table is one spine shared by every product. These are the
 * properties that make that true, and they are easy to break by accident: a
 * NOT NULL flight column, or a default that quietly mislabels a hotel as a flight.
 */
class BookingSpineTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_booking_is_a_tbo_air_flight_unless_told_otherwise(): void
    {
        $booking = Booking::factory()->create();

        $this->assertSame(BookingProduct::Flight, $booking->product);
        $this->assertSame(Supplier::TboAir, $booking->supplier);
    }

    /**
     * An unsaved model must read the same as a saved one — otherwise code that
     * branches on `product` behaves differently either side of a save.
     */
    public function test_the_defaults_apply_before_the_row_is_written(): void
    {
        $booking = new Booking;

        $this->assertSame(BookingProduct::Flight, $booking->product);
        $this->assertSame(Supplier::TboAir, $booking->supplier);
    }

    /**
     * The migration that unblocked hotels. `result_index` was NOT NULL text, so a
     * booking with no TBO ResultIndex — which every hotel booking is — could not
     * physically be written.
     */
    public function test_a_hotel_booking_needs_no_result_index_or_trace_id(): void
    {
        $booking = Booking::create([
            'reference' => 'MT-HOTELROW',
            'product' => BookingProduct::Hotel,
            'supplier' => Supplier::TboHotel,
            'user_id' => User::factory()->create()->id,
            'environment' => 'test',
            'status' => BookingStatus::Quoted,
            'currency' => 'PHP',
            'total_amount' => 4200,
            'quote' => ['price' => ['currency' => 'PHP', 'total' => 4200]],
            'pax' => [['type' => 'Adult', 'title' => 'Ms', 'firstName' => 'Ana', 'lastName' => 'Cruz']],
        ]);

        $this->assertNull($booking->fresh()->result_index);
        $this->assertNull($booking->fresh()->trace_id);
        $this->assertSame(BookingProduct::Hotel, $booking->fresh()->product);
        $this->assertDatabaseHas('bookings', ['reference' => 'MT-HOTELROW', 'product' => 'hotel']);
    }

    public function test_each_product_names_its_supplier_reference(): void
    {
        $this->assertSame('PNR', BookingProduct::Flight->referenceLabel());
        $this->assertSame('Confirmation no.', BookingProduct::Hotel->referenceLabel());
    }
}
