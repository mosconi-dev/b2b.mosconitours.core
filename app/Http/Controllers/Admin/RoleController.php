<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DuplicateRoleRequest;
use App\Http\Requests\Admin\StoreRoleRequest;
use App\Http\Requests\Admin\SyncRolePermissionsRequest;
use App\Http\Requests\Admin\UpdateRoleRequest;
use App\Models\Agency;
use App\Models\Role;
use App\Services\Rbac\PermissionRegistry;
use App\Services\Rbac\RoleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RoleController extends Controller
{
    public function __construct(private readonly RoleService $roles) {}

    public function index(Request $request): View
    {
        $actor = $request->user();

        $roles = Role::visibleTo($actor)
            ->with('agency:id,name,code')
            ->withCount(['users', 'permissions'])
            ->orderByDesc('is_system')
            ->orderBy('label')
            ->get();

        return view('admin.roles.index', [
            'roles' => $roles,
            // Only platform staff choose which agency a new role belongs to; for an
            // agency member the owner is forced to their own agency.
            'agencies' => $actor->isPlatformStaff()
                ? Agency::active()->orderBy('name')->get(['id', 'name', 'code'])
                : collect(),
        ]);
    }

    public function store(StoreRoleRequest $request): RedirectResponse
    {
        $role = $this->roles->create(
            $request->safe()->only(['name', 'description', 'agency_id']),
            $request->validated('permissions', []),
            $request->user(),
        );

        return redirect()->route('admin.roles.edit', $role)
            ->with('status', 'Role created — configure its permissions below.');
    }

    public function edit(Request $request, Role $role, PermissionRegistry $registry): View
    {
        return view('admin.roles.edit', [
            'role' => $role,
            'sections' => $registry->grid($request->user()),
            'sectionLabels' => $registry->sectionLabels(),
            'selected' => $role->permissions->pluck('id')->all(),
            'unmanageable' => $this->roles->unmanageablePermissionLabels($role, $request->user()),
        ]);
    }

    public function update(UpdateRoleRequest $request, Role $role): RedirectResponse
    {
        $this->roles->update($role, $request->safe()->only(['name', 'description']));

        return back()->with('status', 'Role details updated.');
    }

    public function syncPermissions(SyncRolePermissionsRequest $request, Role $role): RedirectResponse
    {
        $this->roles->syncPermissions($role, $request->validated('permissions', []), $request->user());

        return back()->with('status', 'Permissions updated.');
    }

    public function duplicate(DuplicateRoleRequest $request, Role $role): RedirectResponse
    {
        $copy = $this->roles->duplicate($role, $request->validated('name'));

        return redirect()->route('admin.roles.edit', $copy)
            ->with('status', "Role duplicated from “{$role->label}”.");
    }

    public function destroy(Role $role): RedirectResponse
    {
        $this->roles->delete($role);

        return redirect()->route('admin.roles.index')
            ->with('status', 'Role deleted.');
    }
}
