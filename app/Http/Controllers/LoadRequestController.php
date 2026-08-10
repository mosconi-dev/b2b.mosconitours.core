<?php

namespace App\Http\Controllers;

use App\Enums\LoadRequestStatus;
use App\Http\Requests\ReviewLoadRequestRequest;
use App\Http\Requests\StoreLoadRequestRequest;
use App\Models\WalletLoadRequest;
use App\Services\Wallet\LoadRequestService;
use App\Services\Wallet\WalletService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LoadRequestController extends Controller
{
    public function __construct(
        private readonly LoadRequestService $requests,
        private readonly WalletService $wallets,
    ) {}

    /**
     * The queue. Scoped like every other list: an agency member sees only their
     * own agency's requests, platform staff see every agency's.
     */
    public function index(Request $request): View
    {
        $status = $request->query('status');

        $loadRequests = WalletLoadRequest::visibleTo($request->user())
            ->with(['agency:id,name,code', 'requester:id,name', 'reviewer:id,name'])
            ->when(
                in_array($status, LoadRequestStatus::values(), true),
                fn ($q) => $q->where('status', $status),
            )
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('wallet.requests.index', [
            'loadRequests' => $loadRequests,
            'status' => $status,
            'statuses' => LoadRequestStatus::cases(),
            'pendingCount' => WalletLoadRequest::visibleTo($request->user())
                ->where('status', LoadRequestStatus::Pending)
                ->count(),
        ]);
    }

    public function create(Request $request): View
    {
        $agency = $request->user()->agency;

        return view('wallet.requests.create', [
            'agency' => $agency,
            'wallet' => $this->wallets->for($agency),
        ]);
    }

    public function store(StoreLoadRequestRequest $request): RedirectResponse
    {
        $loadRequest = $this->requests->request(
            $request->user()->agency,
            $request->user(),
            $request->safe()->only(['amount', 'payment_reference', 'remarks']),
        );

        return redirect()->route('wallet.requests.index')
            ->with('status', "Load request {$loadRequest->reference} submitted for review.");
    }

    public function approve(ReviewLoadRequestRequest $request, WalletLoadRequest $loadRequest): RedirectResponse
    {
        $approved = $this->requests->approve($loadRequest, $request->user(), $request->validated('remarks'));

        return back()->with(
            'status',
            "{$approved->reference} approved — {$approved->formattedAmount()} credited to {$approved->agency->name}."
        );
    }

    public function reject(ReviewLoadRequestRequest $request, WalletLoadRequest $loadRequest): RedirectResponse
    {
        $rejected = $this->requests->reject($loadRequest, $request->user(), $request->validated('remarks'));

        return back()->with('status', "{$rejected->reference} rejected.");
    }

    public function cancel(Request $request, WalletLoadRequest $loadRequest): RedirectResponse
    {
        $cancelled = $this->requests->cancel($loadRequest, $request->user());

        return back()->with('status', "{$cancelled->reference} cancelled.");
    }
}
