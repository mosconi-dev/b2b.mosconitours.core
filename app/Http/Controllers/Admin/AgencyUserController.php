<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAgencyUserRequest;
use App\Models\Agency;
use App\Models\Role;
use App\Services\Rbac\UserAdminService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Creating a user from within an agency, so the flow never leaves
 * /admin/agencies/{agency}. The agency is the route, not a form field.
 */
class AgencyUserController extends Controller
{
    public function __construct(private readonly UserAdminService $users) {}

    public function create(Agency $agency): View
    {
        return view('admin.agencies.users.create', [
            'agency' => $agency,
            // A user may only hold roles from their own scope, so offer exactly the
            // ones this agency owns.
            'roles' => Role::where('agency_id', $agency->id)->orderBy('label')->get(),
        ]);
    }

    public function store(StoreAgencyUserRequest $request, Agency $agency): RedirectResponse
    {
        $user = $this->users->create(
            $request->safe()->only(['name', 'email', 'password']) + ['agency_id' => $agency->id],
            $request->validated('roles', []),
            $request->user(),
        );

        return redirect()->route('admin.agencies.show', $agency)
            ->with('status', "User “{$user->name}” added to {$agency->name}.");
    }
}
