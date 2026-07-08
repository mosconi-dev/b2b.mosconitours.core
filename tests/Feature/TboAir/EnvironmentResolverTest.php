<?php

namespace Tests\Feature\TboAir;

use App\Services\Settings\Settings;
use App\Services\TboAir\TboAirConfig;
use App\Services\TboAir\TboEnvironmentResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnvironmentResolverTest extends TestCase
{
    use RefreshDatabase;

    private function resolver(): TboEnvironmentResolver
    {
        return app(TboEnvironmentResolver::class);
    }

    public function test_defaults_to_the_config_default(): void
    {
        config(['tboair.default' => 'test']);

        $this->assertSame('test', $this->resolver()->resolve());
    }

    public function test_global_setting_overrides_the_config_default(): void
    {
        app(Settings::class)->set(TboEnvironmentResolver::SETTING_KEY, 'live');

        $this->assertSame('live', $this->resolver()->resolve());
    }

    public function test_unknown_environment_normalizes_to_test(): void
    {
        app(Settings::class)->set(TboEnvironmentResolver::SETTING_KEY, 'bogus');

        $this->assertSame('test', $this->resolver()->resolve());
    }

    public function test_config_flattener_returns_environment_specific_hosts(): void
    {
        $this->assertStringContainsString('api-stage.tboair.com', TboAirConfig::for('test')['search_url']);
        $this->assertStringContainsString('xmloutapi.tboair.com', TboAirConfig::for('test')['auth_url']);

        $this->assertStringContainsString('tbo-api.tboair.com', TboAirConfig::for('live')['search_url']);
        $this->assertStringContainsString('searchapi.tboair.com', TboAirConfig::for('live')['auth_url']);
    }
}
