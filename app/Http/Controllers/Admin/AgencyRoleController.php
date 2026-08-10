<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAgencyRoleRequest;
use App\Models\Agency;
use App\Services\Rbac\PermissionRegistry;
use App\Services\Rbac\RoleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Creating a role from within an agency, so the flow never leaves
 * /admin/agencies/{agency}. The owning agency is the route, not a form field.
 *
 * Unlike the global roles screen — which creates the role first and then sends you
 * to its permission grid — this form carries the grid inline, so one submit both
 * creates the role and sets its permissions and the URL stays put.
 */
class AgencyRoleController extends Controller
{
    public function __construct(private readonly RoleService $roles) {}

    public function create(Request $request, Agency $agency, PermissionRegistry $registry): View
    {
        return view('admin.agencies.roles.create', [
            'agency' => $agency,
            'sections' => $registry->grid($request->user()),
            'sectionLabels' => $registry->sectionLabels(),
            'selected' => array_map('intval', (array) old('permissions', [])),
        ]);
    }

    public function store(StoreAgencyRoleRequest $request, Agency $agency): RedirectResponse
    {
        $role = $this->roles->create(
            $request->safe()->only(['name', 'description']) + ['agency_id' => $agency->id],
            $request->validated('permissions', []),
            $request->user(),
        );

        return redirect()->route('admin.agencies.show', ['agency' => $agency, 'tab' => 'roles'])
            ->with('status', "Role “{$role->label}” created for {$agency->name}.");
    }
}
