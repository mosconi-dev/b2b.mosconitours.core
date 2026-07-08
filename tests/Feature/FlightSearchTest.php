<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class FlightSearchTest extends TestCase
{
    use InteractsWithRbac, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function flightUser(): User
    {
        return $this->userWith(['flight.view', 'flight.search']);
    }

    private function authFixture(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/tboair/authenticate.json')), true);
    }

    private function searchFixture(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/tboair/search-oneway.json')), true);
    }

    private function fakeOk(): void
    {
        Http::fake([
            'xmloutapi.tboair.com/*' => Http::response($this->authFixture(), 200),
            'api-stage.tboair.com/*' => Http::response($this->searchFixture(), 200),
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'tripType' => 'oneway',
            'cabin' => 'economy',
            'adults' => 2,
            'children' => 0,
            'infants' => 0,
            'segments' => [
                ['origin' => 'Manila (MNL)', 'dest' => 'Caticlan (MPH)', 'departure' => now()->addWeek()->toDateString()],
            ],
            'returnDate' => null,
        ], $overrides);
    }

    public function test_search_is_forbidden_without_flight_permission(): void
    {
        $this->fakeOk();

        $this->actingAs($this->userWith(['user.view']))
            ->postJson('/flights/search', $this->payload())
            ->assertForbidden();
    }

    public function test_search_returns_normalized_results(): void
    {
        $this->fakeOk();

        $this->actingAs($this->flightUser())
            ->postJson('/flights/search', $this->payload())
            ->assertOk()
            ->assertJsonStructure([
                'results' => [[
                    'resultIndex', 'airlineCode', 'flightNumbers', 'stops', 'duration',
                    'departure' => ['code', 'time'],
                    'arrival' => ['code'],
                    'price' => ['currency', 'offeredFare'],
                    'trips',
                ]],
                'traceId',
                'currency',
            ])
            ->assertJsonPath('results.0.departure.code', 'MNL')
            ->assertJsonPath('results.0.airlineCode', 'PR')
            ->assertJsonPath('traceId', 'trace-0001')
            ->assertJsonPath('currency', 'PHP');
    }

    public function test_request_maps_trip_cabin_and_extracts_iata(): void
    {
        $this->fakeOk();

        $this->actingAs($this->flightUser())
            ->postJson('/flights/search', $this->payload(['cabin' => 'business']))
            ->assertOk();

        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), 'api-stage.tboair.com')) {
                return false;
            }

            return $request['JourneyType'] === 1
                && $request['AdultCount'] === 2
                && $request['IsDomestic'] === true
                && $request['Sources'] === []
                && $request['PreferredAirlines'] === []
                && $request['ResultFareType'] === 0
                && $request['Segments'][0]['Origin'] === 'MNL'
                && $request['Segments'][0]['Destination'] === 'MPH'
                && $request['Segments'][0]['FlightCabinClass'] === '4'
                && str_ends_with($request['Segments'][0]['PreferredDepartureTime'], 'T00:00:00');
        });
    }

    public function test_token_is_cached_across_searches(): void
    {
        $this->fakeOk();
        $user = $this->flightUser();

        // Two DIFFERENT searches (different dates) so both bypass the per-user
        // result cache and genuinely reach the service, sharing the token.
        $this->actingAs($user)->postJson('/flights/search', $this->payload())->assertOk();
        $this->actingAs($user)->postJson('/flights/search', $this->payload([
            'segments' => [
                ['origin' => 'Manila (MNL)', 'dest' => 'Caticlan (MPH)', 'departure' => now()->addWeeks(2)->toDateString()],
            ],
        ]))->assertOk();

        $authCalls = Http::recorded(fn (Request $request) => str_contains($request->url(), 'xmloutapi.tboair.com'));

        $this->assertCount(1, $authCalls);
    }

    public function test_expired_token_triggers_single_reauth(): void
    {
        Http::fake([
            'xmloutapi.tboair.com/*' => Http::response($this->authFixture(), 200),
            'api-stage.tboair.com/*' => Http::sequence()
                ->push(['Response' => ['Error' => ['ErrorCode' => 6, 'ErrorMessage' => 'Token expired']]], 200)
                ->push($this->searchFixture(), 200),
        ]);

        $this->actingAs($this->flightUser())
            ->postJson('/flights/search', $this->payload())
            ->assertOk();

        $authCalls = Http::recorded(fn (Request $request) => str_contains($request->url(), 'xmloutapi.tboair.com'));

        $this->assertCount(2, $authCalls);
    }

    public function test_validation_errors_return_422(): void
    {
        $this->fakeOk();

        $this->actingAs($this->flightUser())
            ->postJson('/flights/search', $this->payload([
                'segments' => [
                    ['origin' => '', 'dest' => 'Caticlan (MPH)', 'departure' => now()->addWeek()->toDateString()],
                ],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('segments.0.origin');
    }

    public function test_provider_failure_returns_502(): void
    {
        Http::fake([
            'xmloutapi.tboair.com/*' => Http::response($this->authFixture(), 200),
            'api-stage.tboair.com/*' => Http::response('', 500),
        ]);

        $this->actingAs($this->flightUser())
            ->postJson('/flights/search', $this->payload())
            ->assertStatus(502);
    }

    public function test_round_trip_sends_two_segments(): void
    {
        $this->fakeOk();

        $this->actingAs($this->flightUser())
            ->postJson('/flights/search', $this->payload([
                'tripType' => 'round',
                'returnDate' => now()->addWeeks(2)->toDateString(),
            ]))
            ->assertOk();

        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), 'api-stage.tboair.com')) {
                return false;
            }

            return $request['JourneyType'] === 2
                && count($request['Segments']) === 2
                && $request['Segments'][1]['Origin'] === 'MPH'
                && $request['Segments'][1]['Destination'] === 'MNL';
        });
    }
}
