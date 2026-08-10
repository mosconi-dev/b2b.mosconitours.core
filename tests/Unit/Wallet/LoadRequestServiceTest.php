<?php

namespace Tests\Unit\Wallet;

use App\Enums\LoadRequestStatus;
use App\Exceptions\WalletException;
use App\Models\Agency;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLoadRequest;
use App\Services\Wallet\LoadRequestService;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoadRequestServiceTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;

    private User $requester;

    private User $reviewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::factory()->create();
        $this->requester = User::factory()->create(['agency_id' => $this->agency->id]);
        $this->reviewer = User::factory()->create();
    }

    private function service(): LoadRequestService
    {
        return app(LoadRequestService::class);
    }

    private function raise(string $amount = '5000.00'): WalletLoadRequest
    {
        return $this->service()->request($this->agency, $this->requester, [
            'amount' => $amount,
            'payment_reference' => 'BDO-123456',
        ]);
    }

    // ---- Raising ---------------------------------------------------------

    public function test_a_request_starts_pending_against_the_agency_wallet(): void
    {
        $request = $this->raise();

        $this->assertSame(LoadRequestStatus::Pending, $request->status);
        $this->assertSame($this->agency->id, $request->agency_id);
        $this->assertSame($this->requester->id, $request->requested_by);
        $this->assertSame('5000.00', (string) $request->amount);
        $this->assertStringStartsWith('LR-', $request->reference);

        // Raising must not move any money.
        $this->assertSame('0.00', (string) $request->wallet->balance);
        $this->assertDatabaseHas('audit_logs', ['event' => 'wallet.load_requested']);
    }

    public function test_the_wallet_is_created_on_the_first_request(): void
    {
        $this->assertSame(0, Wallet::where('agency_id', $this->agency->id)->count());

        $this->raise();

        $this->assertSame(1, Wallet::where('agency_id', $this->agency->id)->count());
    }

    public function test_a_non_positive_request_is_refused(): void
    {
        $this->expectException(WalletException::class);

        $this->raise('0');
    }

    // ---- Approving -------------------------------------------------------

    public function test_approval_credits_the_wallet_exactly_once(): void
    {
        $request = $this->raise('2500.00');

        $approved = $this->service()->approve($request, $this->reviewer, 'Funds received');

        $this->assertSame(LoadRequestStatus::Approved, $approved->status);
        $this->assertSame($this->reviewer->id, $approved->reviewed_by);
        $this->assertNotNull($approved->reviewed_at);
        $this->assertSame('Funds received', $approved->review_remarks);

        $wallet = $approved->wallet->fresh();
        $this->assertSame('2500.00', (string) $wallet->balance);
        $this->assertSame(1, $wallet->transactions()->count());

        // The ledger entry is linked back, which is what makes a second credit impossible.
        $this->assertNotNull($approved->wallet_transaction_id);
        $this->assertSame('credit', $approved->transaction->direction);
        $this->assertTrue($approved->transaction->source->is($approved));
    }

    public function test_a_second_approval_is_refused_and_does_not_double_credit(): void
    {
        $request = $this->raise('1000.00');
        $this->service()->approve($request, $this->reviewer);

        try {
            $this->service()->approve($request->fresh(), $this->reviewer);
            $this->fail('A second approval should have been refused.');
        } catch (WalletException) {
            // expected
        }

        $wallet = $request->wallet->fresh();
        $this->assertSame('1000.00', (string) $wallet->balance);
        $this->assertSame(1, $wallet->transactions()->count());
    }

    public function test_you_cannot_approve_your_own_request(): void
    {
        $request = $this->raise();

        $this->expectException(WalletException::class);

        try {
            $this->service()->approve($request, $this->requester);
        } finally {
            $this->assertSame(LoadRequestStatus::Pending, $request->fresh()->status);
            $this->assertSame('0.00', (string) $request->wallet->fresh()->balance);
        }
    }

    public function test_a_rejected_request_moves_no_money(): void
    {
        $request = $this->raise();

        $rejected = $this->service()->reject($request, $this->reviewer, 'No proof of payment');

        $this->assertSame(LoadRequestStatus::Rejected, $rejected->status);
        $this->assertSame('No proof of payment', $rejected->review_remarks);
        $this->assertSame('0.00', (string) $rejected->wallet->fresh()->balance);
        $this->assertSame(0, $rejected->wallet->transactions()->count());
    }

    public function test_a_rejected_request_cannot_then_be_approved(): void
    {
        $request = $this->raise();
        $this->service()->reject($request, $this->reviewer);

        $this->expectException(WalletException::class);

        $this->service()->approve($request->fresh(), $this->reviewer);
    }

    public function test_you_cannot_reject_your_own_request(): void
    {
        $request = $this->raise();

        $this->expectException(WalletException::class);

        $this->service()->reject($request, $this->requester);
    }

    // ---- Cancelling ------------------------------------------------------

    public function test_a_pending_request_can_be_cancelled(): void
    {
        $request = $this->raise();

        $cancelled = $this->service()->cancel($request, $this->requester);

        $this->assertSame(LoadRequestStatus::Cancelled, $cancelled->status);
        $this->assertSame('0.00', (string) $cancelled->wallet->fresh()->balance);
    }

    public function test_an_approved_request_cannot_be_cancelled(): void
    {
        $request = $this->raise();
        $this->service()->approve($request, $this->reviewer);

        $this->expectException(WalletException::class);

        $this->service()->cancel($request->fresh(), $this->requester);
    }

    // ---- The wallet belongs to the agency, not the user ------------------

    public function test_every_member_of_an_agency_shares_one_balance(): void
    {
        $colleague = User::factory()->create(['agency_id' => $this->agency->id]);
        $service = $this->service();

        $first = $service->request($this->agency, $this->requester, ['amount' => '1000.00']);
        $second = $service->request($this->agency, $colleague, ['amount' => '500.00']);

        $service->approve($first, $this->reviewer);
        $service->approve($second, $this->reviewer);

        $this->assertSame($first->wallet_id, $second->wallet_id);
        $this->assertSame('1500.00', (string) app(WalletService::class)->for($this->agency)->balance);
    }

    public function test_a_requester_leaving_the_agency_does_not_move_the_balance(): void
    {
        $request = $this->raise('800.00');
        $this->service()->approve($request, $this->reviewer);

        $this->requester->update(['agency_id' => Agency::factory()->create()->id]);

        $this->assertSame('800.00', (string) app(WalletService::class)->for($this->agency)->balance);
    }
}
