<?php

namespace Tests\Feature\TboAir;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PayloadCommandTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $name): array
    {
        return json_decode(file_get_contents(base_path("tests/Fixtures/tboair/{$name}")), true);
    }

    private function booking(array $overrides = []): Booking
    {
        return Booking::factory()->create(array_merge([
            'user_id' => User::factory()->create()->id,
            'reference' => 'MT-TESTREF1',
            'environment' => 'test',
            'status' => BookingStatus::Quoted,
            'is_lcc' => true,
            'trace_id' => 'trace-abc-123',
            'result_index' => str_repeat('R', 40),
            'result_type' => 1,
            'total_amount' => '6400.00',
            'quote_raw' => $this->fixture('farequote.json'),
            'seats_available' => [9],
            'pax' => [[
                'type' => 'Adult', 'title' => 'Mr', 'firstName' => 'Juan', 'lastName' => 'Cruz',
                'gender' => 'M', 'isLeadPax' => true,
                'email' => 'agent@example.com', 'mobile' => '09170000000', 'mobileCountryCode' => '63',
                'addressLine1' => '123 Rizal Street', 'city' => 'Makati',
                'countryCode' => 'PH', 'countryName' => 'Philippines', 'dateOfBirth' => '1990-08-15']],
        ], $overrides));
    }

    /**
     * The whole point of the command: it must never reach TBO.
     */
    public function test_it_sends_nothing(): void
    {
        Http::fake();
        $this->booking();

        $this->artisan('tboair:payload MT-TESTREF1')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_it_summarises_the_payload(): void
    {
        Http::fake();
        $this->booking();

        $this->artisan('tboair:payload MT-TESTREF1')
            ->expectsOutputToContain('MT-TESTREF1')
            ->expectsOutputToContain('LCC — Ticket books and issues in one')
            ->expectsOutputToContain('Juan Cruz')
            ->expectsOutputToContain('Nothing was sent')
            ->assertSuccessful();
    }

    public function test_it_finds_a_booking_by_id_or_reference(): void
    {
        Http::fake();
        $booking = $this->booking();

        $this->artisan("tboair:payload {$booking->id}")->assertSuccessful();
        $this->artisan('tboair:payload mt-testref1')->assertSuccessful(); // case-insensitive
        $this->artisan('tboair:payload NOPE')->assertFailed();
    }

    public function test_it_warns_when_seat_availability_is_missing(): void
    {
        Http::fake();
        $this->booking(['seats_available' => []]);

        $this->artisan('tboair:payload MT-TESTREF1')
            ->expectsOutputToContain('no NoOfSeatAvailable')
            ->assertSuccessful(); // a warning, not a blocker
    }

    public function test_it_blocks_on_an_environment_mismatch(): void
    {
        Http::fake();
        $this->booking(['environment' => 'live']);

        $this->artisan('tboair:payload MT-TESTREF1')
            ->expectsOutputToContain('Environment mismatch')
            ->assertFailed();
    }

    public function test_it_blocks_a_non_lcc_ticket_without_a_pnr(): void
    {
        Http::fake();
        $this->booking(['is_lcc' => false, 'status' => BookingStatus::Booked, 'pnr' => null]);

        $this->artisan('tboair:payload MT-TESTREF1 --ticket')
            ->expectsOutputToContain('needs the held PNR')
            ->assertFailed();
    }

    public function test_it_blocks_a_passenger_missing_a_field_tbo_requires(): void
    {
        Http::fake();
        $booking = $this->booking();
        $pax = $booking->pax;
        $pax[0]['addressLine1'] = null;
        $booking->update(['pax' => $pax]);

        $this->artisan('tboair:payload MT-TESTREF1')
            ->expectsOutputToContain('AddressLine1 is empty')
            ->assertFailed();
    }

    public function test_it_blocks_a_booking_that_can_no_longer_be_sent(): void
    {
        Http::fake();
        $this->booking(['status' => BookingStatus::Ticketed]);

        $this->artisan('tboair:payload MT-TESTREF1')
            ->expectsOutputToContain('nothing further can be sent')
            ->assertFailed();
    }

    public function test_it_reports_a_booking_it_cannot_build_a_payload_for(): void
    {
        Http::fake();
        $this->booking(['quote_raw' => null]);

        $this->artisan('tboair:payload MT-TESTREF1')
            ->expectsOutputToContain('Payload could not be built')
            ->assertFailed();
    }

    public function test_json_dumps_the_whole_payload(): void
    {
        Http::fake();
        $this->booking();

        $this->artisan('tboair:payload MT-TESTREF1 --json')
            ->expectsOutputToContain('"Segments_BE"')
            ->assertSuccessful();
    }
}
