<?php

namespace Tests\Feature\Wallet;

use App\Enums\LoadRequestStatus;
use App\Models\Agency;
use App\Models\User;
use App\Models\WalletLoadRequest;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * The full rotation over HTTP: an agency member raises a request, someone holding
 * the approve permission decides it, and the balance moves.
 */
class LoadRequestFlowTest extends TestCase
{
    use InteractsWithRbac, RefreshDatabase;

    private Agency $acme;

    private Agency $rival;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();

        $this->acme = Agency::factory()->create(['code' => 'acme', 'name' => 'Acme Travel']);
        $this->rival = Agency::factory()->create(['code' => 'rival', 'name' => 'Rival Tours']);
    }

    private function requester(): User
    {
        return $this->agencyUserWith($this->acme, ['wallet.view', 'wallet.load.view', 'wallet.load.create', 'wallet.load.cancel']);
    }

    /** Platform-side reviewer: holds the approve permission, no agency of their own. */
    private function reviewer(): User
    {
        return $this->userWith(['wallet.load.view', 'wallet.load.approve']);
    }

    private function raise(User $as, string $amount = '5000.00'): WalletLoadRequest
    {
        $this->actingAs($as)
            ->post(route('wallet.requests.store'), [
                'amount' => $amount,
                'payment_reference' => 'BDO-998877',
            ])
            ->assertRedirect(route('wallet.requests.index'));

        return WalletLoadRequest::latest('id')->firstOrFail();
    }

    // ---- Raising ---------------------------------------------------------

    public function test_a_member_can_raise_a_request_against_their_agency_wallet(): void
    {
        $request = $this->raise($requester = $this->requester());

        $this->assertSame($this->acme->id, $request->agency_id);
        $this->assertSame($requester->id, $request->requested_by);
        $this->assertSame(LoadRequestStatus::Pending, $request->status);
        $this->assertSame('0.00', (string) $request->wallet->balance, 'raising must not move money');
    }

    public function test_raising_requires_the_create_permission(): void
    {
        $viewer = $this->agencyUserWith($this->acme, ['wallet.load.view']);

        $this->actingAs($viewer)->get(route('wallet.requests.create'))->assertForbidden();
        $this->actingAs($viewer)
            ->post(route('wallet.requests.store'), ['amount' => '100.00'])
            ->assertForbidden();
    }

    public function test_platform_staff_cannot_raise_a_request(): void
    {
        // There is no wallet to load — the wallet belongs to an agency.
        $staff = $this->userWith(['wallet.load.view', 'wallet.load.create']);

        $this->actingAs($staff)->get(route('wallet.requests.create'))->assertForbidden();
    }

    public function test_the_amount_must_be_positive_and_sane(): void
    {
        $requester = $this->requester();

        foreach (['0', '-5', 'abc', '1.234'] as $amount) {
            $this->actingAs($requester)
                ->post(route('wallet.requests.store'), ['amount' => $amount])
                ->assertSessionHasErrors('amount');
        }

        $this->assertSame(0, WalletLoadRequest::count());
    }

    // ---- Reviewing -------------------------------------------------------

    public function test_approval_credits_the_agency_wallet(): void
    {
        $request = $this->raise($this->requester(), '2500.00');

        $this->actingAs($this->reviewer())
            ->patch(route('wallet.requests.approve', $request), ['remarks' => 'Deposit confirmed'])
            ->assertRedirect();

        $request->refresh();
        $this->assertSame(LoadRequestStatus::Approved, $request->status);
        $this->assertSame('2500.00', (string) app(WalletService::class)->for($this->acme)->balance);
    }

    public function test_rejection_moves_no_money(): void
    {
        $request = $this->raise($this->requester(), '2500.00');

        $this->actingAs($this->reviewer())
            ->patch(route('wallet.requests.reject', $request), ['remarks' => 'No proof'])
            ->assertRedirect();

        $this->assertSame(LoadRequestStatus::Rejected, $request->fresh()->status);
        $this->assertSame('0.00', (string) app(WalletService::class)->for($this->acme)->balance);
    }

    public function test_reviewing_requires_the_approve_permission(): void
    {
        $request = $this->raise($this->requester());

        // Can see the queue, cannot decide.
        $viewer = $this->userWith(['wallet.load.view']);

        $this->actingAs($viewer)->patch(route('wallet.requests.approve', $request))->assertForbidden();
        $this->actingAs($viewer)->patch(route('wallet.requests.reject', $request))->assertForbidden();
        $this->assertSame(LoadRequestStatus::Pending, $request->fresh()->status);
    }

    public function test_you_cannot_approve_your_own_request_even_holding_the_permission(): void
    {
        // Same person holds create AND approve.
        $both = $this->agencyUserWith($this->acme, [
            'wallet.load.view', 'wallet.load.create', 'wallet.load.approve',
        ]);

        $request = $this->raise($both);

        $this->actingAs($both)->patch(route('wallet.requests.approve', $request))->assertForbidden();

        $this->assertSame(LoadRequestStatus::Pending, $request->fresh()->status);
        $this->assertSame('0.00', (string) app(WalletService::class)->for($this->acme)->balance);
    }

    public function test_a_colleague_holding_the_permission_can_approve(): void
    {
        // Approval is permission-driven, not identity-driven: an agency colleague
        // with the tick may approve, so long as it is not their own request.
        $request = $this->raise($this->requester());
        $colleague = $this->agencyUserWith($this->acme, ['wallet.load.view', 'wallet.load.approve']);

        $this->actingAs($colleague)
            ->patch(route('wallet.requests.approve', $request))
            ->assertRedirect();

        $this->assertSame(LoadRequestStatus::Approved, $request->fresh()->status);
        $this->assertSame('5000.00', (string) app(WalletService::class)->for($this->acme)->balance);
    }

    public function test_a_second_approval_is_rejected_and_does_not_double_credit(): void
    {
        $request = $this->raise($this->requester(), '1000.00');
        $reviewer = $this->reviewer();

        $this->actingAs($reviewer)->patch(route('wallet.requests.approve', $request))->assertRedirect();
        // The row is no longer pending, so the policy refuses the replay outright.
        $this->actingAs($reviewer)->patch(route('wallet.requests.approve', $request))->assertForbidden();

        $wallet = app(WalletService::class)->for($this->acme);
        $this->assertSame('1000.00', (string) $wallet->balance);
        $this->assertSame(1, $wallet->transactions()->count());
    }

    // ---- Cancelling ------------------------------------------------------

    public function test_a_pending_request_can_be_cancelled_by_the_agency(): void
    {
        $request = $this->raise($requester = $this->requester());

        $this->actingAs($requester)
            ->patch(route('wallet.requests.cancel', $request))
            ->assertRedirect();

        $this->assertSame(LoadRequestStatus::Cancelled, $request->fresh()->status);
    }

    public function test_an_approved_request_can_no_longer_be_cancelled(): void
    {
        $request = $this->raise($requester = $this->requester());
        $this->actingAs($this->reviewer())->patch(route('wallet.requests.approve', $request))->assertRedirect();

        $this->actingAs($requester)
            ->patch(route('wallet.requests.cancel', $request))
            ->assertForbidden();
    }

    // ---- Scope -----------------------------------------------------------

    public function test_an_agency_sees_only_its_own_requests(): void
    {
        $mine = $this->raise($this->requester());
        $theirs = WalletLoadRequest::factory()->create(['agency_id' => $this->rival->id]);

        $listed = $this->actingAs($this->requester())
            ->get(route('wallet.requests.index'))
            ->assertOk()
            ->viewData('loadRequests');

        $this->assertTrue($listed->contains($mine->id));
        $this->assertFalse($listed->contains($theirs->id));
    }

    public function test_a_member_cannot_touch_another_agencys_request(): void
    {
        $theirs = WalletLoadRequest::factory()->create(['agency_id' => $this->rival->id]);
        $approver = $this->agencyUserWith($this->acme, ['wallet.load.view', 'wallet.load.approve', 'wallet.load.cancel']);

        $this->actingAs($approver)->patch(route('wallet.requests.approve', $theirs))->assertForbidden();
        $this->actingAs($approver)->patch(route('wallet.requests.cancel', $theirs))->assertForbidden();
    }

    public function test_platform_staff_see_every_agencys_queue(): void
    {
        $mine = $this->raise($this->requester());
        $theirs = WalletLoadRequest::factory()->create(['agency_id' => $this->rival->id]);

        $listed = $this->actingAs($this->reviewer())
            ->get(route('wallet.requests.index'))
            ->assertOk()
            ->viewData('loadRequests');

        $this->assertTrue($listed->contains($mine->id));
        $this->assertTrue($listed->contains($theirs->id));
    }

    // ---- The pending count on the filter ---------------------------------

    public function test_the_pending_filter_carries_the_count_not_the_header(): void
    {
        $this->raise($this->requester());
        $this->raise($this->requester());

        $response = $this->actingAs($this->reviewer())
            ->get(route('wallet.requests.index'))
            ->assertOk();

        $this->assertSame(2, $response->viewData('pendingCount'));
        // The count belongs on the filter it describes, not floating in the header.
        $response->assertDontSee('2 pending');

        // Anchored to the Pending link itself, so the badge cannot pass by merely
        // appearing somewhere else on the page.
        $this->assertMatchesRegularExpression(
            '#href="[^"]*status=pending"[^>]*>\s*Pending\s*<span[^>]*>\s*2\s*</span>#',
            $response->getContent(),
        );
    }

    public function test_the_count_is_hidden_when_nothing_is_pending(): void
    {
        $request = $this->raise($this->requester());
        $this->actingAs($this->reviewer())->patch(route('wallet.requests.approve', $request))->assertRedirect();

        $response = $this->actingAs($this->reviewer())
            ->get(route('wallet.requests.index'))
            ->assertOk();

        $this->assertSame(0, $response->viewData('pendingCount'));
    }

    public function test_the_count_only_covers_the_viewers_own_agency(): void
    {
        $this->raise($this->requester());
        WalletLoadRequest::factory()->count(3)->create(['agency_id' => $this->rival->id]);

        $response = $this->actingAs($this->requester())
            ->get(route('wallet.requests.index'))
            ->assertOk();

        $this->assertSame(1, $response->viewData('pendingCount'), 'another agency\'s queue must not be counted');
    }

    // ---- The wallet, on My Agency ----------------------------------------

    private function walletTab(Agency $agency): string
    {
        return route('admin.agencies.show', ['agency' => $agency, 'tab' => 'wallet']);
    }

    public function test_the_wallet_tab_shows_the_agency_balance_and_ledger(): void
    {
        $request = $this->raise($this->requester(), '1234.00');
        $this->actingAs($this->reviewer())->patch(route('wallet.requests.approve', $request))->assertRedirect();

        $viewer = $this->agencyUserWith($this->acme, ['agency.view', 'wallet.view']);

        $this->actingAs($viewer)
            ->get($this->walletTab($this->acme))
            ->assertOk()
            ->assertSee('1,234.00')
            ->assertSee($request->reference);
    }

    public function test_the_wallet_tab_requires_the_view_permission(): void
    {
        // Can open My Agency, but wallet.view is what puts the balance on it.
        $viewer = $this->agencyUserWith($this->acme, ['agency.view', 'user.view']);

        $this->actingAs($viewer)
            ->get($this->walletTab($this->acme))
            ->assertOk()
            ->assertDontSee('Wallet balance')
            ->assertDontSee($this->walletTab($this->acme));
    }

    public function test_platform_staff_read_an_agencys_wallet_through_that_agency(): void
    {
        // They have no wallet of their own, so there is no page that would show them
        // one — they open the agency whose balance they mean.
        $request = $this->raise($this->requester(), '900.00');
        $staff = $this->userWith(['agency.view', 'wallet.view', 'wallet.load.view', 'wallet.load.approve']);

        $this->actingAs($staff)->patch(route('wallet.requests.approve', $request))->assertRedirect();

        $this->actingAs($staff)
            ->get($this->walletTab($this->acme))
            ->assertOk()
            ->assertSee('900.00');
    }

    public function test_the_balance_is_shared_by_the_whole_agency(): void
    {
        $first = $this->requester();
        $second = $this->agencyUserWith($this->acme, ['agency.view', 'wallet.view', 'wallet.load.view', 'wallet.load.create']);
        $reviewer = $this->reviewer();

        $a = $this->raise($first, '300.00');
        $this->actingAs($reviewer)->patch(route('wallet.requests.approve', $a))->assertRedirect();

        $b = $this->raise($second, '700.00');
        $this->actingAs($reviewer)->patch(route('wallet.requests.approve', $b))->assertRedirect();

        // Either member sees the same combined balance.
        $this->actingAs($second)->get($this->walletTab($this->acme))->assertOk()->assertSee('1,000.00');
    }
}
