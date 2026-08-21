<?php

namespace Tests\Feature\TboAir;

use App\Services\TboAir\DTO\SelectionInput;
use App\Services\TboAir\Exceptions\TboAirException;
use App\Services\TboAir\TboAirService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A failure TBO reports inside a 200.
 *
 * The HTTP status says nothing about whether a call worked. On a Gulf Air result, TBO
 * answered FareQuote with `200 OK` carrying `ErrorCode 28, "Fare Quote failed from the
 * Supplier end"` and no results. Nothing threw: the empty body parsed into a FareQuote
 * with no trips and a net of zero, the pricing engine added the agency's two flat rules
 * to that nothing, and the wizard rendered a bookable page for **₱350**.
 *
 * The agent was looking at a fare that does not exist, on the screen they buy from.
 */
class SupplierErrorTest extends TestCase
{
    use RefreshDatabase;

    private function fakeFareQuote(array $response): void
    {
        Http::fake([
            '*Authenticate*' => Http::response(
                json_decode(file_get_contents(base_path('tests/Fixtures/tboair/authenticate.json')), true), 200
            ),
            '*FareQuote*' => Http::response($response, 200),
        ]);
    }

    public function test_a_supplier_side_failure_inside_a_200_is_not_treated_as_a_quote(): void
    {
        $this->fakeFareQuote([
            'Response' => [
                'Error' => ['ErrorCode' => 28, 'ErrorMessage' => 'Fare Quote failed from the Supplier end. Please try again.'],
                'Results' => [],
            ],
        ]);

        $this->expectException(TboAirException::class);
        $this->expectExceptionMessage('Fare Quote failed from the Supplier end.');

        app(TboAirService::class)->fareQuote(new SelectionInput('trace-1', 'OB1'));
    }

    /** A clean quote must still come back, or the guard has simply broken pricing. */
    public function test_a_successful_quote_is_untouched(): void
    {
        $this->fakeFareQuote([
            'Response' => [
                'Error' => ['ErrorCode' => 0, 'ErrorMessage' => ''],
                'TraceId' => 'trace-1',
                'Results' => [
                    'ResultIndex' => 'OB1',
                    'IsLCC' => false,
                    'Fare' => ['Currency' => 'PHP', 'BaseFare' => 4000, 'Tax' => 1000, 'OfferedFare' => 5000, 'PublishedFare' => 5000],
                    'FareBreakdown' => [['PassengerType' => 1, 'PassengerCount' => 1, 'BaseFare' => 4000, 'Tax' => 1000]],
                    'Segments' => [[]],
                ],
            ],
        ]);

        $quote = app(TboAirService::class)->fareQuote(new SelectionInput('trace-1', 'OB1'));

        $this->assertSame(5000.0, (float) $quote->price['offeredFare']);
    }

    /**
     * Session expiry keeps its own path: guardSession turns code 6 into an auth error
     * so withReauth retries it once. Swallowing it here would break that.
     */
    public function test_an_expired_session_is_still_an_auth_error(): void
    {
        $this->fakeFareQuote([
            'Response' => [
                'Error' => ['ErrorCode' => 6, 'ErrorMessage' => 'Invalid session'],
                'Results' => [],
            ],
        ]);

        try {
            app(TboAirService::class)->fareQuote(new SelectionInput('trace-1', 'OB1'));
            $this->fail('expected a TboAirException');
        } catch (TboAirException $e) {
            $this->assertTrue($e->isAuthError(), 'code 6 must stay an auth error');
        }
    }
}
