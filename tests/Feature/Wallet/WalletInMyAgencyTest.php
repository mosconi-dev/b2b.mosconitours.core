<?php

namespace Tests\Feature\Wallet;

use App\Models\Agency;
use App\Models\User;
use App\Models\WalletLoadRequest;
use App\Services\Rbac\PermissionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * The wallet and its load requests belong to an agency, so for a member they live
 * on My Agency rather than in the sidebar. Platform staff have no My Agency page
 * and review every agency's requests, so they keep the sidebar links.
 */
class WalletInMyAgencyTest extends TestCase
{
    use InteractsWithRbac, RefreshDatabase;

    private Agency $agency;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();

        $this->agency = Agency::factory()->create();
    }

    /**
     * @return array<int, string>
     */
    private function navModulesFor(User $user): array
    {
        $sections = app(PermissionRegistry::class)->navSections($user);

        return collect($sections)->flatten(1)->pluck('module')->all();
    }

    // ---- Sidebar --------------------------------------------------------

    public function test_a_member_gets_no_sidebar_wallet_links(): void
    {
        $member = $this->agencyUserWith($this->agency, ['agency.view', 'wallet.view', 'wallet.load.view']);

        $modules = $this->navModulesFor($member);

        $this->assertNotContains('wallet', $modules);
        $this->assertNotContains('wallet.load', $modules);

        // My Agency is where they go instead, so that link must still be there.
        $this->assertContains('agency', $modules);
    }

    public function test_platform_staff_keep_the_load_requests_link_only(): void
    {
        $staff = $this->userWith(['agency.view', 'wallet.view', 'wallet.load.view']);

        $modules = $this->navModulesFor($staff);

        // They review every agency's queue from here, so this link stays...
        $this->assertContains('wallet.load', $modules);

        // ...but they have no wallet of their own, so /wallet is a dead end for them.
        $this->assertNotContains('wallet', $modules);
    }

    public function test_the_balance_chip_opens_the_my_agency_wallet_tab(): void
    {
        $member = $this->agencyUserWith($this->agency, ['agency.view', 'wallet.view']);

        $this->actingAs($member)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee(route('admin.agencies.show', ['agency' => $this->agency, 'tab' => 'wallet']))
            ->assertSee('title="Agency wallet balance"', escape: false);
    }

    public function test_the_chip_shows_the_balance_without_a_link_when_my_agency_is_out_of_reach(): void
    {
        // Holds wallet.view but cannot open the page the chip links to. Knowing you
        // are short is the point, so the figure stays — only the href goes.
        $member = $this->agencyUserWith($this->agency, ['wallet.view']);

        $this->actingAs($member)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('title="Agency wallet balance"', escape: false)
            ->assertDontSee(route('admin.agencies.show', ['agency' => $this->agency, 'tab' => 'wallet']));
    }

    public function test_the_rendered_sidebar_follows_the_same_rule(): void
    {
        $requests = route('wallet.requests.index');

        $member = $this->agencyUserWith($this->agency, ['agency.view', 'wallet.view', 'wallet.load.view']);
        $this->actingAs($member)->get('/dashboard')->assertOk()->assertDontSee($requests);

        $staff = $this->userWith(['agency.view', 'wallet.view', 'wallet.load.view']);
        $this->actingAs($staff)->get('/dashboard')->assertOk()->assertSee($requests);
    }

    public function test_hiding_the_link_does_not_close_the_page(): void
    {
        // Load requests keep a page of their own; only the sidebar entry went, so it
        // must still answer for a member who reaches it another way.
        $member = $this->agencyUserWith($this->agency, ['wallet.view', 'wallet.load.view']);

        $this->actingAs($member)->get(route('wallet.requests.index'))->assertOk();
    }

    public function test_the_wallet_has_no_page_outside_my_agency(): void
    {
        $member = $this->agencyUserWith($this->agency, ['agency.view', 'wallet.view']);

        // /wallet is gone: the tab is the wallet, and there is nothing to fall back to.
        $this->actingAs($member)->get('/wallet')->assertNotFound();
        $this->assertFalse(Route::has('wallet.index'));
    }

    // ---- The My Agency tab ----------------------------------------------

    public function test_the_tab_lists_this_agencys_requests_only(): void
    {
        $mine = WalletLoadRequest::factory()->create([
            'agency_id' => $this->agency->id,
            'reference' => 'LR-MINE0001',
        ]);
        WalletLoadRequest::factory()->create(['reference' => 'LR-THEIRS01']);

        $staff = $this->userWith(['agency.view', 'wallet.load.view']);

        $this->actingAs($staff)
            ->get(route('admin.agencies.show', ['agency' => $this->agency, 'tab' => 'requests']))
            ->assertOk()
            ->assertSee($mine->reference)
            ->assertDontSee('LR-THEIRS01');
    }

    public function test_a_member_sees_their_own_agencys_requests_on_the_tab(): void
    {
        $mine = WalletLoadRequest::factory()->create([
            'agency_id' => $this->agency->id,
            'reference' => 'LR-MEMBER01',
        ]);

        // user.view too, so there is more than one tab and the strip is drawn.
        $member = $this->agencyUserWith($this->agency, ['agency.view', 'user.view', 'wallet.load.view']);

        $this->actingAs($member)
            ->get(route('admin.agencies.show', ['agency' => $this->agency, 'tab' => 'requests']))
            ->assertOk()
            ->assertSee('Load Requests')
            ->assertSee($mine->reference);
    }

    public function test_request_load_sits_beside_the_balance_and_nowhere_else(): void
    {
        $member = $this->agencyUserWith($this->agency, [
            'agency.view', 'wallet.view', 'wallet.load.view', 'wallet.load.create',
        ]);

        $this->actingAs($member)
            ->get(route('admin.agencies.show', ['agency' => $this->agency, 'tab' => 'wallet']))
            ->assertOk()
            ->assertSee('Request Load')
            ->assertSee(route('wallet.requests.create'));

        // The Load Requests tab lists and decides them; it does not raise them.
        $this->actingAs($member)
            ->get(route('admin.agencies.show', ['agency' => $this->agency, 'tab' => 'requests']))
            ->assertOk()
            ->assertDontSee(route('wallet.requests.create'));
    }

    public function test_platform_staff_get_no_request_load_button(): void
    {
        // They may open any agency's wallet, but the wallet they would load is not
        // theirs — WalletLoadRequestPolicy::create refuses, so the button is absent.
        $staff = $this->userWith(['agency.view', 'wallet.view', 'wallet.load.create']);

        $this->actingAs($staff)
            ->get(route('admin.agencies.show', ['agency' => $this->agency, 'tab' => 'wallet']))
            ->assertOk()
            ->assertDontSee(route('wallet.requests.create'));
    }

    public function test_the_tab_is_absent_without_the_permission(): void
    {
        $viewer = $this->agencyUserWith($this->agency, ['agency.view', 'user.view', 'wallet.view']);

        $this->actingAs($viewer)
            ->get(route('admin.agencies.show', $this->agency))
            ->assertOk()
            ->assertDontSee(route('admin.agencies.show', ['agency' => $this->agency, 'tab' => 'requests']));
    }

    public function test_asking_for_the_tab_without_the_permission_falls_back(): void
    {
        WalletLoadRequest::factory()->create([
            'agency_id' => $this->agency->id,
            'reference' => 'LR-HIDDEN01',
        ]);

        $viewer = $this->agencyUserWith($this->agency, ['agency.view', 'user.view']);

        $this->actingAs($viewer)
            ->get(route('admin.agencies.show', ['agency' => $this->agency, 'tab' => 'requests']))
            ->assertOk()
            ->assertDontSee('LR-HIDDEN01');
    }
}
