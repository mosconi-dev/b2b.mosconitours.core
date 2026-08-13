<?php

namespace Tests\Feature\TboHotel;

use App\Enums\Supplier;
use App\Models\Setting;
use App\Services\Rbac\PermissionRegistry;
use App\Services\Settings\Settings;
use App\Services\Supplier\SupplierEnvironmentResolver;
use App\Services\TboHotel\DTO\PaxRoom;
use App\Services\TboHotel\DTO\SearchInput;
use App\Services\TboHotel\HotelSearchCache;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class HotelSettingsPageTest extends TestCase
{
    use InteractsWithRbac, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);

        config(['tbohotel.default' => 'test', 'tboair.default' => 'test']);
    }

    private function resolver(): SupplierEnvironmentResolver
    {
        return app(SupplierEnvironmentResolver::class);
    }

    public function test_the_page_renders_for_a_permitted_user(): void
    {
        $this->actingAs($this->userWith(['supplier.tbohotel.view']))
            ->get('/admin/tbo-hotel/settings')
            ->assertOk()
            ->assertSee('TBO Hotel Settings')
            ->assertSee('Environment')
            ->assertSee('Connection')
            ->assertSee('Search cache');
    }

    public function test_a_manager_is_given_the_switch(): void
    {
        $this->actingAs($this->userWith(['supplier.tbohotel.view', 'supplier.tbohotel.manage']))
            ->get('/admin/tbo-hotel/settings')
            ->assertOk()
            ->assertSee('Global environment')
            ->assertSee('name="environment"', false)
            ->assertSee(route('admin.tbo-hotel.cache.flush'), false);
    }

    public function test_the_page_is_gated(): void
    {
        $this->actingAs($this->userWith(['supplier.tbo.view']))
            ->get('/admin/tbo-hotel/settings')
            ->assertForbidden();
    }

    /**
     * Seeing the page is not permission to change it — `view` is read-only, and the
     * select is the thing that can point real money at production.
     */
    public function test_viewing_does_not_grant_switching(): void
    {
        $this->actingAs($this->userWith(['supplier.tbohotel.view']))
            ->get('/admin/tbo-hotel/settings')
            ->assertOk()
            ->assertDontSee('name="environment"', false);

        $this->actingAs($this->userWith(['supplier.tbohotel.view']))
            ->put('/admin/tbo-hotel/settings', ['environment' => 'live'])
            ->assertForbidden();

        $this->assertSame('test', $this->resolver()->globalFor(Supplier::TboHotel));
    }

    public function test_a_manager_switches_the_global_environment(): void
    {
        $this->actingAs($this->userWith(['supplier.tbohotel.view', 'supplier.tbohotel.manage']))
            ->put('/admin/tbo-hotel/settings', ['environment' => 'live'])
            ->assertRedirect();

        $this->assertSame('live', $this->resolver()->globalFor(Supplier::TboHotel));
        $this->assertDatabaseHas('settings', ['key' => Supplier::TboHotel->settingKey(), 'value' => 'live']);
    }

    /**
     * The whole reason this page is separate from admin/settings.
     */
    public function test_switching_hotels_leaves_flights_alone(): void
    {
        app(Settings::class)->set(Supplier::TboAir->settingKey(), 'test');

        $this->actingAs($this->userWith(['supplier.tbohotel.view', 'supplier.tbohotel.manage']))
            ->put('/admin/tbo-hotel/settings', ['environment' => 'live']);

        $this->assertSame('live', $this->resolver()->globalFor(Supplier::TboHotel));
        $this->assertSame('test', $this->resolver()->globalFor(Supplier::TboAir));
    }

    public function test_an_unknown_environment_is_refused(): void
    {
        $this->actingAs($this->userWith(['supplier.tbohotel.view', 'supplier.tbohotel.manage']))
            ->put('/admin/tbo-hotel/settings', ['environment' => 'staging'])
            ->assertSessionHasErrors('environment');

        $this->assertSame('test', $this->resolver()->globalFor(Supplier::TboHotel));
    }

    public function test_the_switch_is_audited(): void
    {
        $this->actingAs($this->userWith(['supplier.tbohotel.view', 'supplier.tbohotel.manage']))
            ->put('/admin/tbo-hotel/settings', ['environment' => 'live']);

        $this->assertDatabaseHas('audit_logs', ['event' => 'tbohotel.settings_updated']);
    }

    /**
     * A mismatch between the two suppliers is legal but worth saying out loud — half a
     * booking flow against production is not a state to discover later.
     */
    public function test_a_supplier_mismatch_is_called_out(): void
    {
        app(Settings::class)->set(Supplier::TboAir->settingKey(), 'live');

        $this->actingAs($this->userWith(['supplier.tbohotel.view']))
            ->get('/admin/tbo-hotel/settings')
            ->assertOk()
            ->assertSee('Suppliers disagree');
    }

    public function test_matching_suppliers_are_not_flagged(): void
    {
        $this->actingAs($this->userWith(['supplier.tbohotel.view']))
            ->get('/admin/tbo-hotel/settings')
            ->assertOk()
            ->assertDontSee('Suppliers disagree');
    }

    /**
     * The point of the connection panel: knowing live will work before switching to it,
     * without the page ever printing the password to get there.
     */
    public function test_the_connection_panel_reports_credentials_without_showing_them(): void
    {
        config([
            'tbohotel.environments.live.credentials.username' => 'live-user',
            'tbohotel.environments.live.credentials.password' => 'super-secret',
            'tbohotel.environments.live.base_url' => 'https://api.tbotechnology.in/HotelAPI',
        ]);

        $this->actingAs($this->userWith(['supplier.tbohotel.view']))
            ->get('/admin/tbo-hotel/settings')
            ->assertOk()
            ->assertSee('live-user')
            ->assertSee('Credentials set')
            ->assertDontSee('super-secret');
    }

    public function test_a_missing_credential_is_reported_as_not_configured(): void
    {
        config([
            'tbohotel.environments.live.credentials.username' => 'live-user',
            'tbohotel.environments.live.credentials.password' => '',
        ]);

        $this->actingAs($this->userWith(['supplier.tbohotel.view']))
            ->get('/admin/tbo-hotel/settings')
            ->assertOk()
            ->assertSee('Not configured');
    }

    public function test_flushing_the_cache_needs_manage(): void
    {
        $this->actingAs($this->userWith(['supplier.tbohotel.view']))
            ->post('/admin/tbo-hotel/cache/flush')
            ->assertForbidden();
    }

    /**
     * Flushing moves the generation rather than deleting rows, so the proof is that a
     * key computed after the flush differs from one computed before it.
     */
    public function test_flushing_orphans_every_cached_search(): void
    {
        $cache = app(HotelSearchCache::class);

        $input = new SearchInput(
            checkIn: '2026-09-11',
            checkOut: '2026-09-13',
            rooms: [new PaxRoom(2, 0, [])],
            guestNationality: 'PH',
            locationType: 'city',
            locationCode: '127116',
        );

        $before = $cache->key(1, 'test', $input);

        $this->actingAs($this->userWith(['supplier.tbohotel.view', 'supplier.tbohotel.manage']))
            ->post('/admin/tbo-hotel/cache/flush')
            ->assertRedirect();

        $after = app(HotelSearchCache::class)->key(1, 'test', $input);

        $this->assertNotSame($before, $after);
        $this->assertSame(1, app(HotelSearchCache::class)->generation());
        $this->assertDatabaseHas('audit_logs', ['event' => 'tbohotel.search_cache_flushed']);
    }

    /**
     * Whatever the generation, the key must still separate environments and users —
     * that separation is what stops a test price being served as a live one.
     */
    public function test_the_key_still_separates_environments_and_users(): void
    {
        $cache = app(HotelSearchCache::class);
        $cache->flush();

        $input = new SearchInput(
            checkIn: '2026-09-11',
            checkOut: '2026-09-13',
            rooms: [new PaxRoom(2, 0, [])],
            guestNationality: 'PH',
            locationType: 'city',
            locationCode: '127116',
        );

        $this->assertNotSame($cache->key(1, 'test', $input), $cache->key(1, 'live', $input));
        $this->assertNotSame($cache->key(1, 'test', $input), $cache->key(2, 'test', $input));
    }

    /**
     * A page reachable only by opening another page first is a page nobody finds.
     * Admin → Settings is TBO Air's and says nothing about hotels, so the nav has to
     * offer this one directly.
     */
    public function test_the_settings_page_has_its_own_navigation_entry(): void
    {
        $this->actingAs($this->userWith(['supplier.tbohotel.view']))
            ->get('/admin/tbo-hotel/settings')
            ->assertOk()
            ->assertSee('TBO Hotel Settings')
            ->assertSee(route('admin.tbo-hotel.settings'), false)
            // Both doors into the module, side by side in the sidebar.
            ->assertSee(route('admin.hotel-catalogue.index'), false);
    }

    public function test_the_navigation_entry_follows_the_module_permission(): void
    {
        $registry = app(PermissionRegistry::class);

        $labels = fn ($user): array => collect($registry->navSections($user))
            ->flatten(1)->pluck('label')->all();

        $this->assertContains('TBO Hotel Settings', $labels($this->userWith(['supplier.tbohotel.view'])));
        $this->assertNotContains('TBO Hotel Settings', $labels($this->userWith(['flight.view'])));
    }

    public function test_the_catalogue_page_links_to_the_settings_page(): void
    {
        $this->actingAs($this->userWith(['supplier.tbohotel.view']))
            ->get('/admin/hotel-catalogue')
            ->assertOk()
            ->assertSee(route('admin.tbo-hotel.settings'), false);
    }

    /**
     * Settings live in the same table as everything else, so the key has to be the
     * hotel one and nothing else.
     */
    public function test_it_writes_only_the_hotel_setting_key(): void
    {
        $this->actingAs($this->userWith(['supplier.tbohotel.view', 'supplier.tbohotel.manage']))
            ->put('/admin/tbo-hotel/settings', ['environment' => 'live']);

        $this->assertSame(
            ['tbohotel.environment'],
            Setting::pluck('key')->all(),
        );
    }
}
