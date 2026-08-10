<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AgencyType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAgencyRequest;
use App\Http\Requests\Admin\UpdateAgencyRequest;
use App\Models\Agency;
use App\Models\Role;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Rbac\AgencyService;
use App\Services\Wallet\WalletService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class AgencyController extends Controller
{
    public function __construct(
        private readonly AgencyService $agencies,
        private readonly WalletService $wallets,
    ) {}

    public function index(Request $request): View
    {
        $agencies = Agency::visibleTo($request->user())
            ->with('parent:id,name,code')
            ->withCount('users')
            ->orderBy('name')
            ->paginate(20);

        return view('admin.agencies.index', compact('agencies'));
    }

    /**
     * The agency hub: who belongs to it and which roles it owns — the two things the
     * index cannot show. Both lists are additionally passed through visibleTo() so a
     * scoped viewer can never be shown a row the rest of the admin area would hide.
     */
    public function show(Request $request, Agency $agency): View
    {
        $actor = $request->user();

        // agency.view lets you see the agency, not automatically its people or its
        // roles — those are gated by their own permissions, and the tab falls back to
        // whichever the viewer actually holds (or none at all).
        $tabs = array_values(array_filter([
            $actor->can('user.view') ? 'users' : null,
            $actor->can('role.view') ? 'roles' : null,
            $actor->can('wallet.view') ? 'wallet' : null,
        ]));

        $requested = $request->query('tab');
        $tab = in_array($requested, $tabs, true) ? $requested : ($tabs[0] ?? null);

        $agency->load('parent:id,name,code');

        if (in_array('users', $tabs, true)) {
            $agency->loadCount('users');
        }

        if (in_array('roles', $tabs, true)) {
            $agency->loadCount('roles');
        }

        $users = null;
        $roles = null;
        $wallet = null;
        $entries = null;

        if ($tab === 'wallet') {
            // Created on first view so the office always has something to adjust.
            $wallet = $this->wallets->for($agency);
            $entries = WalletTransaction::where('wallet_id', $wallet->id)
                ->with('user:id,name')
                ->latest('created_at')
                ->latest('id')
                ->paginate(20)
                ->withQueryString();
        } elseif ($tab === 'roles') {
            $roles = Role::visibleTo($actor)
                ->where('agency_id', $agency->id)
                ->withCount(['users', 'permissions'])
                ->orderBy('label')
                ->paginate(20)
                ->withQueryString();
        } elseif ($tab === 'users') {
            $users = User::visibleTo($actor)
                ->where('agency_id', $agency->id)
                ->with('roles:id,label')
                ->orderBy('name')
                ->paginate(20)
                ->withQueryString();
        }

        return view('admin.agencies.show', compact('agency', 'tab', 'tabs', 'users', 'roles', 'wallet', 'entries'));
    }

    public function create(Request $request): View
    {
        return view('admin.agencies.create', [
            'types' => AgencyType::cases(),
            'parents' => $this->parentOptions($request->user()),
        ]);
    }

    public function store(StoreAgencyRequest $request): RedirectResponse
    {
        $agency = $this->agencies->create(
            $request->safe()->only(['name', 'code', 'type', 'parent_id', 'contact_email', 'contact_phone', 'address']),
            $request->file('logo'),
        );

        return redirect()->route('admin.agencies.index')
            ->with('status', "Agency “{$agency->name}” created.");
    }

    public function edit(Request $request, Agency $agency): View
    {
        return view('admin.agencies.edit', [
            'agency' => $agency,
            'types' => AgencyType::cases(),
            'parents' => $this->parentOptions($request->user(), $agency),
        ]);
    }

    public function update(UpdateAgencyRequest $request, Agency $agency): RedirectResponse
    {
        $this->agencies->update(
            $agency,
            $request->safe()->only(['name', 'type', 'parent_id', 'contact_email', 'contact_phone', 'address']),
            $request->file('logo'),
            $request->boolean('remove_logo'),
        );

        return redirect()->route('admin.agencies.index')
            ->with('status', 'Agency updated.');
    }

    public function toggleActive(Agency $agency): RedirectResponse
    {
        $this->agencies->toggleActive($agency);

        return back()->with('status', 'Agency status updated.');
    }

    public function destroy(Agency $agency): RedirectResponse
    {
        $this->agencies->delete($agency);

        return redirect()->route('admin.agencies.index')
            ->with('status', 'Agency deleted.');
    }

    /**
     * Candidates for the "reports to" field. An agency can never be its own parent;
     * deeper cycles are caught by AgencyService.
     *
     * Scoped to what the actor may see, so the dropdown never becomes a directory of
     * every partner. For an agency member that leaves only their own agency, which is
     * then excluded — i.e. they cannot re-parent, which is the intent.
     *
     * @return Collection<int, Agency>
     */
    private function parentOptions(User $actor, ?Agency $exclude = null): Collection
    {
        return Agency::visibleTo($actor)
            ->when($exclude, fn ($q) => $q->whereKeyNot($exclude->id))
            ->orderBy('name')
            ->get(['id', 'name', 'code']);
    }
}
