<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DuplicateRoleRequest;
use App\Http\Requests\Admin\StoreRoleRequest;
use App\Http\Requests\Admin\SyncRolePermissionsRequest;
use App\Http\Requests\Admin\UpdateRoleRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Rbac\PermissionRegistry;
use App\Services\Rbac\RoleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class RoleController extends Controller
{
    public function __construct(private readonly RoleService $roles) {}

    public function index(): View
    {
        $roles = Role::withCount(['users', 'permissions'])
            ->orderByDesc('is_system')
            ->orderBy('label')
            ->get();

        return view('admin.roles.index', compact('roles'));
    }

    public function store(StoreRoleRequest $request): RedirectResponse
    {
        $role = $this->roles->create(
            $request->safe()->only(['name', 'description']),
            $request->validated('permissions', []),
        );

        return redirect()->route('admin.roles.edit', $role)
            ->with('status', 'Role created — configure its permissions below.');
    }

    public function edit(Role $role, PermissionRegistry $registry): View
    {
        return view('admin.roles.edit', [
            'role' => $role,
            'sections' => $this->permissionGrid($registry),
            'sectionLabels' => [
                'administration' => 'Administration',
                'travel_operations' => 'Travel Operations',
            ],
            'selected' => $role->permissions->pluck('id')->all(),
        ]);
    }

    public function update(UpdateRoleRequest $request, Role $role): RedirectResponse
    {
        $this->roles->update($role, $request->safe()->only(['name', 'description']));

        return back()->with('status', 'Role details updated.');
    }

    public function syncPermissions(SyncRolePermissionsRequest $request, Role $role): RedirectResponse
    {
        $this->roles->syncPermissions($role, $request->validated('permissions', []));

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

    /**
     * Build the permission grid: section -> modules -> action checkboxes,
     * enriching DB permission rows with registry labels/sections.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function permissionGrid(PermissionRegistry $registry): array
    {
        $modules = $registry->modules();
        $labels = config('rbac.action_labels', []);
        $sections = [];

        foreach (Permission::orderBy('module')->orderBy('id')->get()->groupBy('module') as $moduleKey => $perms) {
            $meta = $modules[$moduleKey] ?? [];
            $section = $meta['section'] ?? 'travel_operations';

            $sections[$section][] = [
                'key' => $moduleKey,
                'label' => $meta['label'] ?? $moduleKey,
                'group' => $meta['group'] ?? null,
                'enabled' => $meta['enabled'] ?? true,
                'ids' => $perms->pluck('id')->all(),
                'permissions' => $perms->map(fn (Permission $p): array => [
                    'id' => $p->id,
                    'label' => $labels[$p->action] ?? ucfirst($p->action),
                ])->values()->all(),
            ];
        }

        return $sections;
    }
}
