<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class FlightSearchCacheTest extends TestCase
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

    private function fixture(string $name): array
    {
        return json_decode(file_get_contents(base_path("tests/Fixtures/tboair/{$name}")), true);
    }

    private function fakeOk(): void
    {
        Http::fake([
            'xmloutapi.tboair.com/*' => Http::response($this->fixture('authenticate.json'), 200),
            'api-stage.tboair.com/*' => Http::response($this->fixture('search-oneway.json'), 200),
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'tripType' => 'oneway',
            'cabin' => 'economy',
            'adults' => 1,
            'children' => 0,
            'infants' => 0,
            'segments' => [
                ['origin' => 'Manila (MNL)', 'dest' => 'Caticlan (MPH)', 'departure' => now()->addWeek()->toDateString()],
            ],
            'returnDate' => null,
        ], $overrides);
    }

    private function searchCalls(): int
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), 'api-stage.tboair.com'))->count();
    }

    public function test_identical_search_is_served_from_cache(): void
    {
        $this->fakeOk();
        $user = $this->flightUser();

        $first = $this->actingAs($user)->postJson('/flights/search', $this->payload())->assertOk()->json();
        $second = $this->actingAs($user)->postJson('/flights/search', $this->payload())->assertOk()->json();

        $this->assertSame(1, $this->searchCalls()); // second served from cache
        $this->assertEquals($first, $second);
    }

    public function test_different_search_hits_api_again(): void
    {
        $this->fakeOk();
        $user = $this->flightUser();

        $this->actingAs($user)->postJson('/flights/search', $this->payload())->assertOk();
        $this->actingAs($user)->postJson('/flights/search', $this->payload([
            'segments' => [
                ['origin' => 'Manila (MNL)', 'dest' => 'Cebu (CEB)', 'departure' => now()->addWeek()->toDateString()],
            ],
        ]))->assertOk();

        $this->assertSame(2, $this->searchCalls());
    }

    public function test_cache_is_per_user(): void
    {
        $this->fakeOk();

        $this->actingAs($this->flightUser())->postJson('/flights/search', $this->payload())->assertOk();
        $this->actingAs($this->flightUser())->postJson('/flights/search', $this->payload())->assertOk();

        $this->assertSame(2, $this->searchCalls()); // different users never share a cache entry
    }

    public function test_provider_failure_is_not_cached(): void
    {
        Http::fake([
            'xmloutapi.tboair.com/*' => Http::response($this->fixture('authenticate.json'), 200),
            'api-stage.tboair.com/*' => Http::response('', 500),
        ]);
        $user = $this->flightUser();

        $this->actingAs($user)->postJson('/flights/search', $this->payload())->assertStatus(502);
        $this->actingAs($user)->postJson('/flights/search', $this->payload())->assertStatus(502);

        $this->assertSame(2, $this->searchCalls()); // nothing cached, so the second attempt re-hits
    }
}
