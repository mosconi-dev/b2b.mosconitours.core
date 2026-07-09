<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class FarePipelineTest extends TestCase
{
    use InteractsWithRbac, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function apiUser(): User
    {
        return $this->userWith(['flight.view', 'flight.search']);
    }

    private function fixture(string $name): array
    {
        return json_decode(file_get_contents(base_path("tests/Fixtures/tboair/{$name}")), true);
    }

    private function fakeOk(): void
    {
        Http::fake([
            '*Authenticate*' => Http::response($this->fixture('authenticate.json'), 200),
            '*FareQuote*' => Http::response($this->fixture('farequote.json'), 200),
            '*FareRule*' => Http::response($this->fixture('fare-rule.json'), 200),
        ]);
    }

    private function selection(): array
    {
        return ['traceId' => 'trace-abc-123', 'resultIndex' => 'OB1'];
    }

    public function test_fare_quote_requires_flight_search_permission(): void
    {
        $this->fakeOk();

        $this->actingAs($this->userWith(['flight.view']))
            ->postJson(route('flights.fare-quote'), $this->selection())
            ->assertForbidden();
    }

    public function test_fare_quote_returns_the_repriced_fare(): void
    {
        $this->fakeOk();

        $res = $this->actingAs($this->apiUser())
            ->postJson(route('flights.fare-quote'), $this->selection())
            ->assertOk()
            ->assertJsonPath('resultIndex', 'OB1')
            ->assertJsonPath('isLcc', true)
            ->assertJsonPath('isRefundable', true)
            ->assertJsonPath('isPriceChanged', true)
            ->assertJsonPath('isPassportMandatory', false)
            ->assertJsonPath('price.currency', 'PHP')
            ->assertJsonPath('fareBreakdown.0.passengerType', 'Adult')
            ->assertJsonPath('fareBreakdown.0.count', 1);

        // Per-passenger breakdown + offered vs published price parsed from FareQuote.
        $this->assertEqualsWithDelta(6400, $res->json('price.offeredFare'), 0.001);
        $this->assertEqualsWithDelta(6500, $res->json('price.publishedFare'), 0.001);
        $this->assertEqualsWithDelta(5200, $res->json('fareBreakdown.0.baseFare'), 0.001);
    }

    public function test_fare_quote_validates_the_selection(): void
    {
        $this->actingAs($this->apiUser())
            ->postJson(route('flights.fare-quote'), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['traceId', 'resultIndex']);
    }

    public function test_fare_quote_accepts_a_long_result_index(): void
    {
        $this->fakeOk();

        // Real TBO ResultIndex tokens routinely exceed 255 characters.
        $this->actingAs($this->apiUser())
            ->postJson(route('flights.fare-quote'), [
                'traceId' => 'trace-abc-123',
                'resultIndex' => str_repeat('A', 600),
            ])
            ->assertOk();
    }

    public function test_fare_quote_reauthenticates_once_on_expired_session(): void
    {
        Http::fake([
            '*Authenticate*' => Http::response($this->fixture('authenticate.json'), 200),
            '*FareQuote*' => Http::sequence()
                ->push($this->fixture('farequote-expired.json'), 200) // ErrorCode 6
                ->push($this->fixture('farequote.json'), 200),         // succeeds after re-auth
        ]);

        $this->actingAs($this->apiUser())
            ->postJson(route('flights.fare-quote'), $this->selection())
            ->assertOk()
            ->assertJsonPath('isPriceChanged', true);

        $auths = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'Authenticate'))
            ->count();

        $this->assertSame(2, $auths); // one initial + one re-auth
    }

    public function test_fare_quote_returns_502_on_provider_failure(): void
    {
        Http::fake([
            '*Authenticate*' => Http::response($this->fixture('authenticate.json'), 200),
            '*FareQuote*' => Http::response('', 500),
        ]);

        $this->actingAs($this->apiUser())
            ->postJson(route('flights.fare-quote'), $this->selection())
            ->assertStatus(502);
    }

    public function test_fare_rule_returns_readable_rules(): void
    {
        $this->fakeOk();

        $res = $this->actingAs($this->apiUser())
            ->postJson(route('flights.fare-rule'), $this->selection())
            ->assertOk()
            ->assertJsonPath('resultIndex', 'OB1')
            ->assertJsonPath('rules.0.origin', 'MNL')
            ->assertJsonPath('rules.0.destination', 'CEB')
            ->assertJsonPath('rules.0.airline', '5J');

        // The HTML rule text is normalized: tags stripped, <br/> -> newlines, entities decoded.
        $detail = $res->json('rules.0.detail');
        $this->assertStringNotContainsString('<br', $detail);
        $this->assertStringContainsString("TICKET RESTRICTIONS:\nCancellation:", $detail);
        $this->assertStringContainsString('Rebooking allowed & subject to fees', $detail);
        $this->assertStringEndsWith('subject to fees.', $detail); // trailing <br/> <br/> trimmed
    }

    public function test_fare_endpoints_record_api_logs(): void
    {
        $this->fakeOk();

        $this->actingAs($this->apiUser())
            ->postJson(route('flights.fare-quote'), $this->selection())
            ->assertOk();

        $this->assertDatabaseHas('tbo_air_api_logs', ['type' => 'farequote', 'successful' => true]);
    }
}
