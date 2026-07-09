<?php

namespace Tests\Feature\Admin;

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

    public function test_manager_can_switch_environment_and_edit_cache_key(): void
    {
        $this->actingAs($this->admin())
            ->put(route('admin.settings.tbo.update'), ['environment' => 'live', 'cache_key' => 'tboair.tok'])
            ->assertRedirect();

        $this->assertSame('live', app(Settings::class)->get(TboEnvironmentResolver::SETTING_KEY));
        $this->assertSame('tboair.tok', app(Settings::class)->get('tbo.cache_key'));
        $this->assertDatabaseHas('audit_logs', ['event' => 'tbo.settings_updated']);
    }

    public function test_update_requires_manage_permission(): void
    {
        $this->actingAs($this->userWith(['setting.view']))
            ->put(route('admin.settings.tbo.update'), ['environment' => 'live', 'cache_key' => 'x'])
            ->assertForbidden();
    }

    public function test_environment_must_be_test_or_live(): void
    {
        $this->actingAs($this->admin())
            ->put(route('admin.settings.tbo.update'), ['environment' => 'staging', 'cache_key' => 'x'])
            ->assertSessionHasErrors('environment');
    }

    public function test_flush_forgets_the_environment_token(): void
    {
        $base = app(Settings::class)->get('tbo.cache_key', config('tboair.cache_key'));
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

    public function test_editable_cache_key_is_used_by_the_service(): void
    {
        app(Settings::class)->set('tbo.cache_key', 'custom.key');

        $this->assertSame('custom.key:test', app(TboAirService::class)->cacheKey());
    }
}
