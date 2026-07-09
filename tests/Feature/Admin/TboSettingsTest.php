<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Services\Settings\Settings;
use App\Services\TboAir\TboAirService;
use App\Services\TboAir\TboEnvironmentResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class TboSettingsTest extends TestCase
{
    use InteractsWithRbac, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    public function test_settings_page_requires_setting_view(): void
    {
        $this->actingAs($this->userWith(['user.view']))
            ->get(route('admin.settings.index'))
            ->assertForbidden();

        $this->actingAs($this->admin())
            ->get(route('admin.settings.index'))
            ->assertOk()
            ->assertSee('TBO Air Environment');
    }

    public function test_settings_page_shows_the_cached_token_per_environment(): void
    {
        $base = config('tboair.cache_key');
        Cache::put($base.':live', '60a09026-499e-4f17-a139-59bdac39a364', 3600);

        $this->actingAs($this->admin())
            ->get(route('admin.settings.index'))
            ->assertOk()
            ->assertSee('60a09026-499e-4f17-a139-59bdac39a364');
    }

    public function test_manager_can_switch_the_global_environment(): void
    {
        $this->actingAs($this->admin())
            ->put(route('admin.settings.tbo.update'), ['environment' => 'live'])
            ->assertRedirect();

        $this->assertSame('live', app(Settings::class)->get(TboEnvironmentResolver::SETTING_KEY));
        $this->assertDatabaseHas('audit_logs', ['event' => 'tbo.settings_updated']);
    }

    public function test_manager_can_set_a_token_and_ttl_together(): void
    {
        $base = config('tboair.cache_key');

        $this->actingAs($this->admin())
            ->put(route('admin.settings.tbo.env', 'live'), ['ttl' => 900, 'token' => 'LIVE_TOK'])
            ->assertRedirect();

        $this->assertEquals(900, app(Settings::class)->get('tbo.token_ttl.live'));
        $this->assertSame('LIVE_TOK', Cache::get($base.':live'));
        $this->assertDatabaseHas('audit_logs', ['event' => 'tbo.settings_updated']);
    }

    public function test_ttl_and_token_must_be_provided_together(): void
    {
        // A TTL without a token is rejected...
        $this->actingAs($this->admin())
            ->put(route('admin.settings.tbo.env', 'live'), ['ttl' => 900])
            ->assertSessionHasErrors(['token'], null, 'tbo_live');

        // ...and a token without a TTL is rejected.
        $this->actingAs($this->admin())
            ->put(route('admin.settings.tbo.env', 'test'), ['token' => 'TOK'])
            ->assertSessionHasErrors(['ttl'], null, 'tbo_test');
    }

    public function test_ttl_must_not_exceed_the_ceiling(): void
    {
        $this->actingAs($this->admin())
            ->put(route('admin.settings.tbo.env', 'live'), ['ttl' => 999999, 'token' => 'TOK']) // above the 86400s ceiling
            ->assertSessionHasErrors(['ttl'], null, 'tbo_live');
    }

    public function test_a_short_ttl_below_the_old_floor_is_allowed(): void
    {
        // The 60s minimum was removed; a very short TTL is now accepted (paired with a token).
        $this->actingAs($this->admin())
            ->put(route('admin.settings.tbo.env', 'test'), ['ttl' => 5, 'token' => 'TOK'])
            ->assertRedirect();

        $this->assertEquals(5, app(Settings::class)->get('tbo.token_ttl.test'));
    }

    public function test_saving_both_empty_clears_the_token_and_resets_the_ttl(): void
    {
        $base = config('tboair.cache_key');
        app(Settings::class)->set('tbo.token_ttl.live', 120); // existing override
        Cache::put($base.':live', 'LIVE_TOKEN', 3600);

        $this->actingAs($this->admin())
            ->put(route('admin.settings.tbo.env', 'live'), ['ttl' => '', 'token' => '']) // both blank = reset
            ->assertRedirect();

        // Token cleared (re-auth on next call) and the TTL override reset to the default.
        $this->assertNull(Cache::get($base.':live'));
        $this->assertNull(app(Settings::class)->get('tbo.token_ttl.live'));
        $this->assertDatabaseHas('audit_logs', ['event' => 'tbo.token_flushed']);
    }

    public function test_saving_both_empty_with_no_cache_logs_no_flush(): void
    {
        $this->actingAs($this->admin())
            ->put(route('admin.settings.tbo.env', 'test'), ['ttl' => '', 'token' => ''])
            ->assertRedirect();

        // Nothing was cached, so no spurious flush entry.
        $this->assertDatabaseMissing('audit_logs', ['event' => 'tbo.token_flushed']);
    }

    public function test_service_uses_the_per_environment_ttl_override(): void
    {
        app(Settings::class)->set('tbo.token_ttl.test', 120);

        $this->assertSame(120, app(TboAirService::class)->tokenTtl());
    }

    public function test_update_requires_manage_permission(): void
    {
        $this->actingAs($this->userWith(['setting.view']))
            ->put(route('admin.settings.tbo.update'), ['environment' => 'live'])
            ->assertForbidden();
    }

    public function test_environment_must_be_test_or_live(): void
    {
        $this->actingAs($this->admin())
            ->put(route('admin.settings.tbo.update'), ['environment' => 'staging'])
            ->assertSessionHasErrors('environment');
    }

    public function test_flush_forgets_the_environment_token(): void
    {
        $base = config('tboair.cache_key');
        Cache::put($base.':live', 'TOKEN123', 60);

        $this->actingAs($this->admin())
            ->post(route('admin.settings.tbo.flush', 'live'))
            ->assertRedirect();

        $this->assertNull(Cache::get($base.':live'));
        $this->assertDatabaseHas('audit_logs', ['event' => 'tbo.token_flushed']);
    }

    public function test_flush_rejects_an_unknown_environment(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.settings.tbo.flush', 'staging'))
            ->assertNotFound();
    }

    public function test_manager_can_seed_a_token_for_reuse(): void
    {
        $base = config('tboair.cache_key');

        $this->actingAs($this->admin())
            ->put(route('admin.settings.tbo.env', 'live'), [
                'ttl' => 900,
                'token' => '20adac70-0096-4ca8-9df1-8766dc8c94c3',
            ])
            ->assertRedirect();

        // The next call reuses this token instead of authenticating, stored under the saved TTL.
        $this->assertSame('20adac70-0096-4ca8-9df1-8766dc8c94c3', Cache::get($base.':live'));
        $this->assertDatabaseHas('audit_logs', ['event' => 'tbo.token_seeded']);
    }

    public function test_env_save_never_stores_the_raw_token_in_the_audit_log(): void
    {
        $token = '20adac70-0096-4ca8-9df1-8766dc8c94c3';

        $this->actingAs($this->admin())
            ->put(route('admin.settings.tbo.env', 'live'), ['ttl' => 900, 'token' => $token])
            ->assertRedirect();

        $properties = AuditLog::where('event', 'tbo.token_seeded')->value('properties');

        $this->assertStringNotContainsString($token, (string) json_encode($properties));
        $this->assertSame('live', $properties['environment']);
    }

    public function test_env_save_requires_manage_permission(): void
    {
        $this->actingAs($this->userWith(['setting.view']))
            ->put(route('admin.settings.tbo.env', 'live'), ['ttl' => 900])
            ->assertForbidden();
    }

    public function test_env_save_rejects_an_unknown_environment(): void
    {
        $this->actingAs($this->admin())
            ->put(route('admin.settings.tbo.env', 'staging'), ['ttl' => 900])
            ->assertNotFound();
    }

    public function test_service_uses_the_config_cache_key_namespaced_by_environment(): void
    {
        $this->assertSame(config('tboair.cache_key').':test', app(TboAirService::class)->cacheKey());
    }

    public function test_saving_clears_a_legacy_stored_cache_key(): void
    {
        // A base key used to be editable; ensure a stale stored value is dropped on save.
        app(Settings::class)->set('tbo.cache_key', '20adac70-0096-4ca8-9df1-8766dc8c94c3');

        $this->actingAs($this->admin())
            ->put(route('admin.settings.tbo.update'), ['environment' => 'test'])
            ->assertRedirect();

        $this->assertNull(app(Settings::class)->get('tbo.cache_key'));
    }
}
