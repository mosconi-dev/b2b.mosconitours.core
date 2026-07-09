<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ResetUserPasswordRequest;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\Activity;
use App\Models\Role;
use App\Models\TboAirApiLog;
use App\Models\User;
use App\Services\Rbac\UserAdminService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UserController extends Controller
{
    public function __construct(private readonly UserAdminService $users) {}

    public function index(): View
    {
        $users = User::with('roles:id,name,label')
            ->orderBy('name')
            ->paginate(20);

        return view('admin.users.index', compact('users'));
    }

    public function create(): View
    {
        return view('admin.users.create', [
            'roles' => Role::orderBy('label')->get(),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $user = $this->users->create(
            $request->safe()->only(['name', 'email', 'password']),
            $request->validated('roles', []),
        );

        return redirect()->route('admin.users.index')
            ->with('status', "User “{$user->name}” created.");
    }

    public function edit(User $user): View
    {
        return view('admin.users.edit', [
            'user' => $user->load('roles:id'),
            'roles' => Role::orderBy('label')->get(),
        ]);
    }

    public function logs(Request $request, User $user): View
    {
        $tab = $request->query('tab') === 'activity' ? 'activity' : 'api';
        $type = $request->query('type');
        $logs = null;
        $entries = null;

        if ($tab === 'activity') {
            // In-app movement (navigation + key actions).
            $entries = Activity::where('user_id', $user->id)
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
        $data = $request->safe()->only(['name', 'email']);

        // Only TBO managers may set a per-user environment override.
        if ($request->user()->can('supplier.tbo.manage')) {
            $data['tbo_environment'] = $request->validated('tbo_environment');
        }

        $this->users->update($user, $data, $request->validated('roles', []));

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
}
