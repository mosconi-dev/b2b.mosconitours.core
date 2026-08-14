<?php

namespace Tests\Feature\TboAir;

use App\Enums\Supplier;
use App\Services\Settings\Settings;
use App\Services\Supplier\SupplierEnvironmentResolver;
use App\Services\TboAir\TboAirConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnvironmentResolverTest extends TestCase
{
    use RefreshDatabase;

    private function resolver(): SupplierEnvironmentResolver
    {
        return app(SupplierEnvironmentResolver::class);
    }

    public function test_defaults_to_the_config_default(): void
    {
        config(['tboair.default' => 'test']);

        $this->assertSame('test', $this->resolver()->resolve(Supplier::TboAir));
    }

    public function test_global_setting_overrides_the_config_default(): void
    {
        app(Settings::class)->set(Supplier::TboAir->settingKey(), 'live');

        $this->assertSame('live', $this->resolver()->resolve(Supplier::TboAir));
    }

    public function test_unknown_environment_normalizes_to_test(): void
    {
        app(Settings::class)->set(Supplier::TboAir->settingKey(), 'bogus');

        $this->assertSame('test', $this->resolver()->resolve(Supplier::TboAir));
    }

    /**
     * The whole point of keying this on a supplier: switching flights to live must
     * not drag hotels along with it, and the two settings must not collide.
     */
    public function test_each_supplier_resolves_independently(): void
    {
        app(Settings::class)->set(Supplier::TboHotel->settingKey(), 'live');

        $this->assertSame('live', $this->resolver()->resolve(Supplier::TboHotel));
        $this->assertSame('test', $this->resolver()->resolve(Supplier::TboAir));

        $this->assertSame([Supplier::TboHotel], $this->resolver()->liveSuppliers());
    }

    public function test_no_supplier_is_live_by_default(): void
    {
        $this->assertSame([], $this->resolver()->liveSuppliers());
    }

    public function test_config_flattener_returns_environment_specific_hosts(): void
    {
        $this->assertStringContainsString('api-stage.tboair.com', TboAirConfig::for('test')['search_url']);
        $this->assertStringContainsString('xmloutapi.tboair.com', TboAirConfig::for('test')['auth_url']);

        $this->assertStringContainsString('tbo-api.tboair.com', TboAirConfig::for('live')['search_url']);
        $this->assertStringContainsString('searchapi.tboair.com', TboAirConfig::for('live')['auth_url']);
    }
}
