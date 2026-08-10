<?php

namespace Tests\Feature\TboAir;

use App\Enums\TboBookingStatus;
use App\Services\TboAir\DTO\BookingResult;
use App\Services\TboAir\Exceptions\TboAirException;
use App\Services\TboAir\TboAirService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BookingDetailsTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $name): array
    {
        return json_decode(file_get_contents(base_path("tests/Fixtures/tboair/{$name}")), true);
    }

    private function fake(?array $details = null): void
    {
        Http::fake([
            '*Authenticate*' => Http::response($this->fixture('authenticate.json'), 200),
            '*GetBookingDetails*' => Http::response($details ?? $this->fixture('bookingdetails.json'), 200),
        ]);
    }

    private function service(): TboAirService
    {
        return app(TboAirService::class);
    }

    public function test_it_reads_the_authoritative_state_of_a_pnr(): void
    {
        $this->fake();

        $result = $this->service()->bookingDetails('984XIX');

        // The fixture is a real response: the itinerary sits under `Itinerary`, not
        // the `FlightItinerary` the doc page names.
        $this->assertSame('984XIX', $result->pnr);
        $this->assertSame('75133', $result->bookingId);
        $this->assertSame(TboBookingStatus::Successful, $result->status);
        $this->assertTrue($result->hasPnr());
        $this->assertFalse($result->needsReconciliation());
    }

    public function test_it_sends_the_pnr_and_a_token(): void
    {
        $this->fake();
        $this->service()->bookingDetails('984XIX');

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'GetBookingDetails')) {
                return false;
            }

            return $request->data()['PNR'] === '984XIX' && filled($request->data()['TokenId']);
        });
    }

    /**
     * The whole point of this call is that it is current — a cached answer would
     * defeat it, so two reads must be two calls.
     */
    public function test_it_is_never_cached(): void
    {
        $this->fake();

        $this->service()->bookingDetails('984XIX');
        $this->service()->bookingDetails('984XIX');

        Http::assertSentCount(3); // one Authenticate + two GetBookingDetails
    }

    public function test_it_raises_when_tbo_knows_no_such_booking(): void
    {
        $this->fake([
            'Response' => [
                'ResponseStatus' => 2,
                'Error' => ['ErrorCode' => 1000, 'ErrorMessage' => 'No booking found'],
            ],
        ]);

        $this->expectException(TboAirException::class);
        $this->expectExceptionMessage('No booking found');

        $this->service()->bookingDetails('NOPE00');
    }

    // ---- Response shape ---------------------------------------------------

    public function test_it_reads_a_flat_book_response(): void
    {
        $result = BookingResult::fromResponse([
            'PNR' => 'ABC123',
            'BookingId' => 991,
            'Status' => 1,
            'IsPriceChanged' => true,
        ]);

        $this->assertSame('ABC123', $result->pnr);
        $this->assertSame('991', $result->bookingId);
        $this->assertSame(TboBookingStatus::Successful, $result->status);
        $this->assertTrue($result->isPriceChanged);
    }

    /**
     * TBO uses "" and "0" where it means null. A booking must not appear to hold a
     * PNR called "0".
     */
    public function test_it_treats_empty_and_zero_identifiers_as_absent(): void
    {
        $result = BookingResult::fromResponse(['PNR' => '', 'BookingId' => '0', 'Status' => 8]);

        $this->assertNull($result->pnr);
        $this->assertNull($result->bookingId);
        $this->assertFalse($result->hasPnr());
    }

    public function test_an_in_progress_result_demands_reconciliation(): void
    {
        $result = BookingResult::fromResponse(['PNR' => 'ABC123', 'Status' => 8]);

        $this->assertTrue($result->needsReconciliation());
        $this->assertTrue($result->hasPnr(), 'a PNR exists even though the status is unresolved');
    }

    /**
     * A PNR with no status at all is the most dangerous shape: something exists at
     * TBO and we know nothing about it.
     */
    public function test_a_missing_status_demands_reconciliation(): void
    {
        $result = BookingResult::fromResponse(['PNR' => 'ABC123']);

        $this->assertNull($result->status);
        $this->assertTrue($result->needsReconciliation());
    }
}
