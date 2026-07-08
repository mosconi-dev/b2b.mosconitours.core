<?php

namespace App\Services\Rbac;

use App\Models\Permission;
use App\Models\User;

/**
 * The only reader of the module registry.
 *
 * It does not care where the module definitions come from — today they are read
 * from config('rbac.modules'); tomorrow that array can be composed from per-module
 * files with no change here. Gates, nav and the catalog all derive from this class,
 * so no permission name is ever declared twice.
 */
class PermissionRegistry
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $modulesCache = null;

    /**
     * All module definitions, normalized with defaults applied.
     *
     * @return array<string, array<string, mixed>>
     */
    public function modules(): array
    {
        return $this->modulesCache ??= collect(config('rbac.modules', []))
            ->map(fn (array $m): array => array_merge([
                'label' => null,
                'section' => 'travel_operations',
                'group' => null,
                'route' => null,
                'icon' => null,
                'enabled' => true,
                'actions' => [],
            ], $m))
            ->all();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function enabledModules(): array
    {
        return array_filter($this->modules(), fn (array $m): bool => $m['enabled'] === true);
    }

    public function enabled(string $module): bool
    {
        return (bool) ($this->modules()[$module]['enabled'] ?? false);
    }

    /**
     * Flattened permission definitions for ENABLED modules (drives the gate loop).
     *
     * @return array<int, array{name: string, module: string, action: string, label: string}>
     */
    public function all(): array
    {
        $out = [];

        foreach ($this->enabledModules() as $key => $module) {
            foreach ($module['actions'] as $action) {
                $out[] = $this->definition($key, $module, $action);
            }
        }

        return $out;
    }

    /**
     * @return array<int, string>
     */
    public function permissionNames(): array
    {
        return array_column($this->all(), 'name');
    }

    /**
     * The ability that gates a module's nav entry / general access
     * (prefers the "view" action, else the first declared action).
     */
    public function primaryAbility(string $module): string
    {
        $actions = $this->modules()[$module]['actions'] ?? [];
        $action = in_array('view', $actions, true) ? 'view' : ($actions[0] ?? 'view');

        return "{$module}.{$action}";
    }

    /**
     * Route-bearing, enabled modules the user may see, grouped by section.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function navSections(?User $user): array
    {
        $sections = [];

        foreach ($this->enabledModules() as $key => $module) {
            if (empty($module['route'])) {
                continue;
            }

            $ability = $this->primaryAbility($key);

            if ($user === null || ! $user->can($ability)) {
                continue;
            }

            $sections[$module['section']][] = [
                'module' => $key,
                'label' => $module['label'] ?? $key,
                'route' => $module['route'],
                'icon' => $module['icon'],
                'group' => $module['group'],
                'permission' => $ability,
            ];
        }

        return $sections;
    }

    /**
     * Idempotently upsert permission rows for ALL modules (enabled or not) so
     * pre-assignments survive a module being toggled off. Orphans (rows no longer
     * in the registry) are reported, and deleted only when $prune is true.
     *
     * @return array{synced: int, orphans: array<int, string>, pruned: bool}
     */
    public function sync(bool $prune = false): array
    {
        $names = [];

        foreach ($this->modules() as $key => $module) {
            foreach ($module['actions'] as $action) {
                $definition = $this->definition($key, $module, $action);

                Permission::updateOrCreate(
                    ['name' => $definition['name']],
                    [
                        'module' => $definition['module'],
                        'action' => $definition['action'],
                        'label' => $definition['label'],
                    ],
                );

                $names[] = $definition['name'];
            }
        }

        $orphans = Permission::whereNotIn('name', $names)->pluck('name')->all();

        if ($prune && $orphans !== []) {
            Permission::whereIn('name', $orphans)->delete();
        }

        return ['synced' => count($names), 'orphans' => $orphans, 'pruned' => $prune];
    }

    /**
     * @param  array<string, mixed>  $module
     * @return array{name: string, module: string, action: string, label: string}
     */
    private function definition(string $key, array $module, string $action): array
    {
        $labels = config('rbac.action_labels', []);

        return [
            'name' => "{$key}.{$action}",
            'module' => $key,
            'action' => $action,
            'label' => ($module['label'] ?? $key).' · '.($labels[$action] ?? ucfirst($action)),
        ];
    }
}
