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
                // 'all' = everyone who holds the permission gets a sidebar link;
                // 'platform' = only users with no agency of their own, because the
                // page is already reachable as a tab on My Agency; 'none' = no link
                // at all, the page is reached from somewhere else entirely.
                'nav' => 'all',
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

            // A member reaches these from My Agency; only platform staff, who have
            // no such page, still need the link. The permission is unchanged either
            // way — this hides a door, it does not lock one.
            $linked = match ($module['nav']) {
                'platform' => $user->isPlatformStaff(),
                'none' => false,
                default => true,
            };

            if (! $linked) {
                continue;
            }

            $sections[$module['section']][] = [
                'module' => $key,
                'icon' => $module['icon'],
                'group' => $module['group'],
                'permission' => $ability,
                ...$this->navTarget($key, $module, $user),
            ];

            foreach ($this->extraLinks($key, $module, $user) as $link) {
                $sections[$module['section']][] = $link;
            }
        }

        return $sections;
    }

    /**
     * A module's further destinations, if it declares any.
     *
     * One module usually means one page, but not always: TBO Hotel has a catalogue
     * and its own settings, and a page reachable only by opening another one first
     * is a page nobody finds. Declaring them here rather than inventing a second
     * module keeps the permission set honest — these are extra doors into the same
     * room, not new rooms.
     *
     * @param  array<string, mixed>  $module
     * @return array<int, array<string, mixed>>
     */
    private function extraLinks(string $key, array $module, User $user): array
    {
        $links = [];

        foreach ($module['links'] ?? [] as $link) {
            if (empty($link['route']) || empty($link['label'])) {
                continue;
            }

            // Defaults to whatever gates the module itself, so a link cannot quietly
            // be looser than the page it points at.
            $ability = isset($link['action']) ? "{$key}.{$link['action']}" : $this->primaryAbility($key);

            if (! $user->can($ability)) {
                continue;
            }

            $links[] = [
                'module' => $key,
                'icon' => $link['icon'] ?? $module['icon'],
                'group' => $module['group'],
                'permission' => $ability,
                'label' => $link['label'],
                'route' => $link['route'],
                'params' => [],
            ];
        }

        return $links;
    }

    /**
     * Label, route and route params for a nav item.
     *
     * Agencies is the one module whose destination depends on the viewer: an agency
     * member can only ever reach their own, so sending them to a list of exactly one
     * row is pointless — they go straight to it, under "My Agency".
     *
     * @param  array<string, mixed>  $module
     * @return array{label: string, route: string, params: array<int, mixed>}
     */
    private function navTarget(string $key, array $module, User $user): array
    {
        if ($key === 'agency' && ! $user->isPlatformStaff()) {
            return [
                'label' => 'My Agency',
                'route' => 'admin.agencies.show',
                'params' => [$user->agency_id],
            ];
        }

        return [
            'label' => $module['label'] ?? $key,
            'route' => $module['route'],
            'params' => [],
        ];
    }

    /**
     * The permission checkbox grid: section -> modules -> actions, enriching DB
     * permission rows with registry labels/sections.
     *
     * For an agency member the catalog is capped at the permissions they hold
     * themselves, so the UI never offers a checkbox RoleService would refuse (see
     * its no-escalation guard). Platform staff see everything.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function grid(User $actor): array
    {
        $modules = $this->modules();
        $labels = config('rbac.action_labels', []);
        $sections = [];

        $catalog = Permission::query()
            ->unless($actor->isPlatformStaff(), fn ($q) => $q->whereIn('name', $actor->permissionNames()))
            ->orderBy('module')
            ->orderBy('id')
            ->get();

        foreach ($catalog->groupBy('module') as $moduleKey => $perms) {
            $meta = $modules[$moduleKey] ?? [];

            $sections[$meta['section'] ?? 'travel_operations'][] = [
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

    /**
     * Display names for the grid's two sections.
     *
     * @return array<string, string>
     */
    public function sectionLabels(): array
    {
        return [
            'administration' => 'Administration',
            'travel_operations' => 'Travel Operations',
        ];
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
