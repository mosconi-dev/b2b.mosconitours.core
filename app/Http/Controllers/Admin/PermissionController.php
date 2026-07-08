<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Services\Rbac\AuditLogger;
use App\Services\Rbac\PermissionRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class PermissionController extends Controller
{
    public function index(PermissionRegistry $registry): View
    {
        $modules = $registry->modules();
        $labels = config('rbac.action_labels', []);
        $sections = [];

        $permissions = Permission::withCount('roles')->orderBy('module')->orderBy('id')->get();

        foreach ($permissions->groupBy('module') as $moduleKey => $perms) {
            $meta = $modules[$moduleKey] ?? [];
            $section = $meta['section'] ?? 'travel_operations';

            $sections[$section][] = [
                'key' => $moduleKey,
                'label' => $meta['label'] ?? $moduleKey,
                'enabled' => $meta['enabled'] ?? true,
                'permissions' => $perms->map(fn (Permission $p): array => [
                    'name' => $p->name,
                    'label' => $labels[$p->action] ?? ucfirst($p->action),
                    'roles_count' => $p->roles_count,
                ])->values()->all(),
            ];
        }

        return view('admin.permissions.index', [
            'sections' => $sections,
            'sectionLabels' => [
                'administration' => 'Administration',
                'travel_operations' => 'Travel Operations',
            ],
            'total' => $permissions->count(),
        ]);
    }

    public function sync(PermissionRegistry $registry, AuditLogger $audit): RedirectResponse
    {
        $result = $registry->sync();
        $audit->log('permission.synced', null, $result);

        $message = "Synced {$result['synced']} permissions from the registry.";

        if ($result['orphans'] !== []) {
            $message .= ' '.count($result['orphans']).' orphan(s) detected (not removed).';
        }

        return back()->with('status', $message);
    }
}
