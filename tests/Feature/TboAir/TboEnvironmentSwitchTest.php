<?php

namespace Tests\Feature\TboAir;

use App\Services\Settings\Settings;
use App\Services\TboAir\FlightResultTransformer;
use App\Services\TboAir\TboAirClient;
use App\Services\TboAir\TboAirConfig;
use App\Services\TboAir\TboAirService;
use App\Services\TboAir\TboEnvironmentResolver;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class TboEnvironmentSwitchTest extends TestCase
{
    use InteractsWithRbac, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function fixture(string $name): array
    {
        return json_decode(file_get_contents(base_path("tests/Fixtures/tboair/{$name}")), true);
    }

    private function payload(): array
    {
        return [
            'tripType' => 'oneway',
            'cabin' => 'economy',
            'adults' => 1,
            'children' => 0,
            'infants' => 0,
            'segments' => [
                ['origin' => 'Manila (MNL)', 'dest' => 'Caticlan (MPH)', 'departure' => now()->addWeek()->toDateString()],
            ],
            'returnDate' => null,
        ];
    }

    public function test_live_setting_routes_the_search_to_live_hosts(): void
    {
        app(Settings::class)->set(TboEnvironmentResolver::SETTING_KEY, 'live');

        Http::fake([
            'searchapi.tboair.com/*' => Http::response($this->fixture('authenticate.json'), 200),
            'tbo-api.tboair.com/*' => Http::response($this->fixture('search-oneway.json'), 200),
        ]);

        $user = $this->userWith(['flight.view', 'flight.search']);
        $this->actingAs($user)->postJson('/flights/search', $this->payload())->assertOk();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'searchapi.tboair.com')); // live auth
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'tbo-api.tboair.com'));   // live search
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'api-stage.tboair.com')); // never test

        $this->assertDatabaseHas('tbo_air_api_logs', ['type' => 'search', 'environment' => 'live']);
    }

    public function test_api_logs_record_the_test_environment(): void
    {
        Http::fake([
            'xmloutapi.tboair.com/*' => Http::response($this->fixture('authenticate.json'), 200),
            'api-stage.tboair.com/*' => Http::response($this->fixture('search-oneway.json'), 200),
        ]);

        $user = $this->userWith(['flight.view', 'flight.search']);
        $this->actingAs($user)->postJson('/flights/search', $this->payload())->assertOk();

        $this->assertDatabaseHas('tbo_air_api_logs', ['type' => 'search', 'environment' => 'test']);
    }

    public function test_token_cache_key_is_namespaced_per_environment(): void
    {
        $transformer = app(FlightResultTransformer::class);
        $settings = app(Settings::class);

        $test = new TboAirService(new TboAirClient(TboAirConfig::for('test')), $transformer, $settings);
        $live = new TboAirService(new TboAirClient(TboAirConfig::for('live')), $transformer, $settings);

        $this->assertStringEndsWith(':test', $test->cacheKey());
        $this->assertStringEndsWith(':live', $live->cacheKey());
        $this->assertNotSame($test->cacheKey(), $live->cacheKey());
    }
}
