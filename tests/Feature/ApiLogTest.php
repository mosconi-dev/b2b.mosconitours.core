<?php

namespace Tests\Feature;

use App\Enums\Supplier;
use App\Models\SupplierApiLog;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class ApiLogTest extends TestCase
{
    use InteractsWithRbac, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function apiUser(): User
    {
        return $this->userWith(['flight.view', 'flight.search', 'apilog.view']);
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

    public function test_search_records_auth_and_search_logs(): void
    {
        $this->fakeOk();
        $user = $this->apiUser();

        $this->actingAs($user)->postJson('/flights/search', $this->payload())->assertOk();

        $this->assertDatabaseCount('supplier_api_logs', 2);
        $this->assertDatabaseHas('supplier_api_logs', ['type' => 'authenticate', 'successful' => true, 'user_id' => $user->id]);
        $this->assertDatabaseHas('supplier_api_logs', ['type' => 'search', 'successful' => true, 'status_code' => 200]);
    }

    public function test_auth_password_is_masked(): void
    {
        $this->fakeOk();

        $this->actingAs($this->apiUser())->postJson('/flights/search', $this->payload())->assertOk();

        $auth = SupplierApiLog::where('type', 'authenticate')->firstOrFail();
        $this->assertSame('********', $auth->request['Password']);
    }

    public function test_failed_search_is_logged(): void
    {
        Http::fake([
            'xmloutapi.tboair.com/*' => Http::response($this->fixture('authenticate.json'), 200),
            'api-stage.tboair.com/*' => Http::response('', 500),
        ]);

        $this->actingAs($this->apiUser())->postJson('/flights/search', $this->payload())->assertStatus(502);

        $this->assertDatabaseHas('supplier_api_logs', ['type' => 'search', 'successful' => false, 'status_code' => 500]);
    }

    public function test_logs_page_renders_with_entries(): void
    {
        $this->fakeOk();
        $user = $this->apiUser();
        $this->actingAs($user)->postJson('/flights/search', $this->payload())->assertOk();

        $this->actingAs($user)->get('/api-logs')
            ->assertOk()
            ->assertSee('API Logs')
            ->assertSee('MNL → MPH');
    }

    public function test_logs_page_requires_auth(): void
    {
        $this->get('/api-logs')->assertRedirect('/login');
    }

    public function test_logs_page_is_forbidden_without_apilog_permission(): void
    {
        $this->actingAs($this->userWith(['flight.view']))
            ->get('/api-logs')
            ->assertForbidden();
    }

    public function test_flight_calls_are_stamped_with_their_supplier(): void
    {
        $this->fakeOk();
        $this->actingAs($this->apiUser())->postJson('/flights/search', $this->payload())->assertOk();

        $this->assertSame(
            [Supplier::TboAir],
            SupplierApiLog::pluck('supplier')->unique()->values()->all(),
        );
    }

    public function test_logs_page_filters_by_supplier(): void
    {
        $this->fakeOk();
        $user = $this->apiUser();
        $this->actingAs($user)->postJson('/flights/search', $this->payload())->assertOk();

        // Stand in for the hotel calls Phase 1 will make: the filter has to exclude
        // one supplier's traffic before there is a second supplier to prove it on.
        SupplierApiLog::create([
            'supplier' => Supplier::TboHotel,
            'type' => 'prebook',
            'environment' => 'test',
            'endpoint' => 'https://api.tbotechnology.in/HotelAPI/PreBook',
            'status_code' => 200,
            'successful' => true,
            'duration_ms' => 12,
            'user_id' => $user->id,
            'agency_id' => $user->agency_id,
            'request' => [],
        ]);

        $this->actingAs($user)->get('/api-logs?supplier=tbohotel')
            ->assertOk()
            ->assertSee('Prebook')
            ->assertDontSee('MNL → MPH');

        $this->actingAs($user)->get('/api-logs?supplier=tboair')
            ->assertOk()
            ->assertSee('MNL → MPH')
            ->assertDontSee('Prebook');
    }

    public function test_log_detail_returns_response_json(): void
    {
        $this->fakeOk();
        $user = $this->apiUser();
        $this->actingAs($user)->postJson('/flights/search', $this->payload())->assertOk();

        $log = SupplierApiLog::where('type', 'search')->firstOrFail();

        $this->actingAs($user)->getJson("/api-logs/{$log->id}")
            ->assertOk()
            ->assertJsonStructure(['response']);
    }
}
