<?php

namespace Tests\Unit\Settings;

use App\Services\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_default_when_missing(): void
    {
        $this->assertSame('fallback', app(Settings::class)->get('nope', 'fallback'));
    }

    public function test_set_persists_and_reads_back(): void
    {
        $settings = app(Settings::class);

        $settings->set('tbo.environment', 'live');

        $this->assertSame('live', $settings->get('tbo.environment'));
        $this->assertDatabaseHas('settings', ['key' => 'tbo.environment', 'value' => 'live']);
    }

    public function test_set_updates_existing_and_invalidates_cache(): void
    {
        $settings = app(Settings::class);

        $settings->set('tbo.environment', 'test');
        $this->assertSame('test', $settings->get('tbo.environment')); // caches
        $settings->set('tbo.environment', 'live');

        $this->assertSame('live', $settings->get('tbo.environment')); // cache invalidated
        $this->assertDatabaseCount('settings', 1);
    }

    public function test_forget_removes_the_setting(): void
    {
        $settings = app(Settings::class);
        $settings->set('tbo.environment', 'live');

        $settings->forget('tbo.environment');

        $this->assertNull($settings->get('tbo.environment'));
        $this->assertDatabaseMissing('settings', ['key' => 'tbo.environment']);
    }
}
