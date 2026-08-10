<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ResetUserPasswordRequest;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\Agency;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\TboAirApiLog;
use App\Models\User;
use App\Services\Rbac\UserAdminService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class UserController extends Controller
{
    public function __construct(private readonly UserAdminService $users) {}

    public function index(Request $request): View
    {
        $users = User::visibleTo($request->user())
            ->with(['roles:id,name,label', 'agency:id,name,code,type'])
            ->orderBy('name')
            ->paginate(20);

        return view('admin.users.index', compact('users'));
    }

    public function create(Request $request): View
    {
        $actor = $request->user();

        return view('admin.users.create', [
            // A new user starts in the actor's own agency, so offer that scope's roles.
            'roles' => $this->roleOptions($actor->agency_id),
            'agencies' => $this->agencyOptions($actor),
            'actor' => $actor,
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $user = $this->users->create(
            $request->safe()->only(['name', 'email', 'password', 'agency_id']),
            $request->validated('roles', []),
            $request->user(),
        );

        return redirect()->route('admin.users.index')
            ->with('status', "User “{$user->name}” created.");
    }

    public function edit(Request $request, User $user): View
    {
        return view('admin.users.edit', [
            'user' => $user->load('roles:id'),
            // A user may only hold roles from their own scope, so offer exactly those.
            'roles' => $this->roleOptions($user->agency_id),
            'agencies' => $this->agencyOptions($request->user()),
            'actor' => $request->user(),
        ]);
    }

    public function logs(Request $request, User $user): View
    {
        $tab = $request->query('tab') === 'activity' ? 'activity' : 'api';
        $type = $request->query('type');
        $logs = null;
        $entries = null;

        if ($tab === 'activity') {
            // Meaningful actions this user performed (create/update/delete, settings,
            // sign-in/out) — sourced from the audit trail, keyed by the acting user.
            $entries = AuditLog::where('user_id', $user->id)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate(20)
                ->withQueryString();
        } else {
            // Outbound TBO API calls. Exclude the heavy `response` JSON from the list
            // (it's fetched lazily by show() when a row is expanded).
            $logs = TboAirApiLog::query()
                ->where('user_id', $user->id)
                ->select(['id', 'type', 'environment', 'endpoint', 'status_code', 'successful', 'duration_ms', 'user_id', 'error', 'request', 'created_at'])
                ->when(in_array($type, ['authenticate', 'search'], true), fn ($q) => $q->where('type', $type))
                ->latest()
                ->paginate(20)
                ->withQueryString();
        }

        return view('admin.users.logs', compact('user', 'tab', 'type', 'logs', 'entries'));
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $data = $request->safe()->only(['name', 'email', 'agency_id']);

        // Only TBO managers may set a per-user environment override.
        if ($request->user()->can('supplier.tbo.manage')) {
            $data['tbo_environment'] = $request->validated('tbo_environment');
        }

        $this->users->update($user, $data, $request->validated('roles', []), $request->user());

        return redirect()->route('admin.users.index')
            ->with('status', 'User updated.');
    }

    public function toggleActive(User $user): RedirectResponse
    {
        $this->users->toggleActive($user);

        return back()->with('status', 'User status updated.');
    }

    public function resetPassword(ResetUserPasswordRequest $request, User $user): RedirectResponse
    {
        $this->users->resetPassword($user, $request->validated('password'));

        return back()->with('status', 'Password reset.');
    }

    public function destroy(User $user): RedirectResponse
    {
        $this->users->delete($user);

        return redirect()->route('admin.users.index')
            ->with('status', 'User deleted.');
    }

    /**
     * Agencies a user may be placed in. Inactive ones are excluded so nobody is
     * assigned into a closed branch by accident. Only platform staff get a choice —
     * an agency member always places people in their own agency.
     *
     * @return Collection<int, Agency>
     */
    private function agencyOptions(User $actor): Collection
    {
        if (! $actor->isPlatformStaff()) {
            return collect();
        }

        return Agency::active()->orderBy('name')->get(['id', 'name', 'code']);
    }

    /**
     * Roles that belong to one scope — an agency, or the platform scope when null.
     *
     * @return Collection<int, Role>
     */
    private function roleOptions(?int $agencyId): Collection
    {
        return Role::query()
            ->when(
                $agencyId === null,
                fn ($q) => $q->whereNull('agency_id'),
                fn ($q) => $q->where('agency_id', $agencyId),
            )
            ->orderBy('label')
            ->get();
    }
}
