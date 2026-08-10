<?php

namespace Tests\Feature\TboAir;

use App\Services\TboAir\Exceptions\TboAirException;
use App\Services\TboAir\TboAirService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class AgencyBalanceTest extends TestCase
{
    use InteractsWithRbac, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    private function fixture(string $name): array
    {
        return json_decode(file_get_contents(base_path("tests/Fixtures/tboair/{$name}")), true);
    }

    private function fakeBalance(?array $response = null): void
    {
        Http::fake([
            '*GetAvailableBalance*' => Http::response($response ?? $this->fixture('balance.json'), 200),
        ]);
    }

    private function service(): TboAirService
    {
        return app(TboAirService::class);
    }

    public function test_it_reads_the_balance_from_tbo(): void
    {
        $this->fakeBalance();

        $balance = $this->service()->agencyBalance();

        $this->assertSame('125430.50', $balance->available);
        $this->assertSame('PHP', $balance->currency);
    }

    /**
     * A live call against the test host returns the *Authenticate* envelope — the
     * balance under `Agency`, with TBO's own "TotalAailableLimit" spelling — not the
     * flat shape the doc page describes. Both are supported; this proves the whole
     * service path on the one we have actually seen on the wire.
     */
    public function test_it_reads_the_envelope_tbo_actually_returns(): void
    {
        $this->fakeBalance($this->fixture('balance-agency.json'));

        $balance = $this->service()->agencyBalance();

        $this->assertSame('125430.50', $balance->available);
        $this->assertSame('PHP', $balance->currency);
    }

    /**
     * A real failure came back as `Errors: [null]` with no message anywhere, which
     * must still produce a usable error rather than an empty string.
     */
    public function test_it_survives_an_error_response_with_no_message(): void
    {
        $this->fakeBalance([
            'Agency' => null,
            'Alerts' => [],
            'Errors' => [null],
            'TokenId' => null,
            'IsSuccess' => false,
            'TrackingId' => 'c4a22e08-a784-408f-9ab4-2c514843ce43',
        ]);

        $this->expectException(TboAirException::class);
        $this->expectExceptionMessage('Could not read the TBO agency balance.');

        $this->service()->agencyBalance();
    }

    /**
     * The call authenticates with credentials rather than a TokenId, so it must send
     * them — and the logged copy must not contain the password in clear.
     */
    public function test_it_authenticates_with_credentials_not_a_token(): void
    {
        $this->fakeBalance();
        $this->service()->agencyBalance();

        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_contains($request->url(), 'GetAvailableBalance')
                && array_key_exists('UserName', $body)
                && array_key_exists('Password', $body)
                && $body['BookingMode'] === 'API'
                && array_key_exists('EndUserIp', $body)
                && ! array_key_exists('TokenId', $body);
        });
    }

    public function test_it_caches_the_balance_and_refreshes_only_when_asked(): void
    {
        $this->fakeBalance();
        $service = $this->service();

        $service->agencyBalance();
        $service->agencyBalance();
        Http::assertSentCount(1);

        $service->agencyBalance(fresh: true);
        Http::assertSentCount(2);
    }

    public function test_the_cache_is_namespaced_per_environment(): void
    {
        $this->assertStringEndsWith(':test', $this->service()->balanceCacheKey());
    }

    public function test_it_fails_loudly_when_tbo_reports_an_error(): void
    {
        $this->fakeBalance([
            'IsSuccess' => false,
            'ErrorCode' => 4,
            'ErrorMessage' => 'Invalid Agency Credentials',
        ]);

        $this->expectException(TboAirException::class);
        $this->expectExceptionMessage('Invalid Agency Credentials');

        $this->service()->agencyBalance();
    }

    public function test_a_failed_read_is_not_cached(): void
    {
        $this->fakeBalance(['IsSuccess' => false, 'ErrorMessage' => 'Nope']);

        try {
            $this->service()->agencyBalance();
        } catch (TboAirException) {
            // expected
        }

        $this->assertNull(Cache::get($this->service()->balanceCacheKey()));
    }

    public function test_has_funds_for_compares_against_the_balance(): void
    {
        $this->fakeBalance();

        $this->assertTrue($this->service()->hasFundsFor('125430.50'));
        $this->assertFalse($this->service()->hasFundsFor('125430.51'));
    }

    // ---- Admin surface ---------------------------------------------------

    public function test_the_settings_page_shows_the_balance_without_calling_tbo(): void
    {
        $this->fakeBalance();
        Cache::put($this->service()->balanceCacheKey(), ['available' => '9999.00', 'currency' => 'PHP']);

        $this->actingAs($this->userWith(['admin.access', 'setting.view', 'supplier.tbo.view']))
            ->get(route('admin.settings.index'))
            ->assertOk()
            ->assertSee('Our balance with TBO')
            ->assertSee('9,999.00');

        // Rendering the page must never spend a supplier call.
        Http::assertNothingSent();
    }

    public function test_the_balance_panel_is_hidden_without_the_permission(): void
    {
        $this->actingAs($this->userWith(['admin.access', 'setting.view']))
            ->get(route('admin.settings.index'))
            ->assertOk()
            ->assertDontSee('Our balance with TBO');
    }

    public function test_check_now_reads_a_fresh_balance(): void
    {
        $this->fakeBalance();

        $this->actingAs($this->userWith(['admin.access', 'setting.view', 'supplier.tbo.view']))
            ->post(route('admin.settings.tbo.balance'))
            ->assertRedirect()
            ->assertSessionHas('status', fn (string $s): bool => str_contains($s, '125,430.50'));

        Http::assertSentCount(1);
    }

    public function test_check_now_reports_a_supplier_failure_instead_of_erroring(): void
    {
        $this->fakeBalance(['IsSuccess' => false, 'ErrorMessage' => 'Invalid Agency Credentials']);

        $this->actingAs($this->userWith(['admin.access', 'setting.view', 'supplier.tbo.view']))
            ->post(route('admin.settings.tbo.balance'))
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $s): bool => str_contains($s, 'Invalid Agency Credentials'));
    }

    public function test_check_now_is_gated(): void
    {
        $this->actingAs($this->userWith(['admin.access', 'setting.view']))
            ->post(route('admin.settings.tbo.balance'))
            ->assertForbidden();
    }
}
