# RBAC — Role-Based Access Control

The Administration area (`/admin`) and the authorization layer that guards every route, nav item, and
action across `b2b.mosconitours.core`. Namespace: `App\Services\Rbac`.

## Design in one paragraph

**Strict custom RBAC on top of native Laravel authorization** — Gates + Policies + `can:` middleware +
`@can`. No `spatie/laravel-permission`, **no `Gate::before`** (so nobody, not even an admin, bypasses a
missing permission — a role grants only what it's explicitly assigned). Permissions are **code-defined**
by a central registry (`config/rbac.php` → `PermissionRegistry`); roles and users are data. A user's
resolved permission set is cached per-user with explicit invalidation.

## Permission naming

`name = <module>[.<subresource>].<action>` — singular, lowercase, dot-separated
(`flight.search`, `role.update`, `supplier.tbo.manage`). The DB stores the full `name` (unique, = the
Gate ability) plus convenience columns `module` (everything before the final dot) and `action` (final
segment). One rule covers 2-segment (`flight.search`) and namespaced 3-segment (`supplier.tbo.manage`).

## The registry — single source of truth

`config/rbac.php` declares every module; `PermissionRegistry` is the only reader.

| Module key | Section | Route | Actions |
| --- | --- | --- | --- |
| `admin` | administration | `admin.dashboard` | access |
| `agency` | administration | `admin.agencies.index` | view, create, update, delete |
| `user` | administration | `admin.users.index` | view, create, update, delete |
| `role` | administration | `admin.roles.index` | view, create, update, delete |
| `permission` | administration | `admin.permissions.index` | view, sync |
| `audit` | administration | `admin.audit-logs.index` | view |
| `setting` | administration | `admin.settings.index` | view, update |
| `flight` | travel_operations | `flights` | view, search, book, issue |
| `booking` | travel_operations | `bookings.index` | view, create, cancel, refund |
| `apilog` | travel_operations | `api-logs` | view |
| `supplier.tbo` | travel_operations (Suppliers) | — | view, sync, manage, live |
| `hotel`, `supplier.amadeus`, `corporate`, `itp`, `resa`, `markup`, `markup.office` | — | `null` | **permission-only stubs** (not built) |

Each module carries `label`, `section` (`administration` \| `travel_operations`), optional `group`
(e.g. "Suppliers"), `route` (named route or `null` for a stub), `icon` (resolved by the
`<x-admin.nav-icon>` component), an `enabled` feature flag, and `actions`.

- `route => null` → the permissions exist and roles can be pre-configured, but there's no nav item yet.
- `enabled => false` → defines **no gate** and is hidden from nav/routes, but its permission rows still
  sync so role assignments survive a toggle.

`PermissionRegistry` exposes: `modules()`, `enabledModules()`, `enabled($module)`, `all()` (flattened
`{name,module,action,label}`), `permissionNames()` (**pure config, no DB** — safe during `migrate`),
`primaryAbility($module)`, `navSections(?User)` (route-bearing enabled modules the user can
`"$module.view"`, grouped `section → group`), and `sync($prune=false)` (idempotent upsert of **all**
modules; `--prune` hard-deletes orphans).

- **`php artisan rbac:sync {--prune}`** → `PermissionRegistry::sync()`. `PermissionSeeder` calls the same.

## Data model (migrations `2026_07_09_00000{1..6}`, cross-DB portable)

- **`roles`** — `name` (unique machine key: `admin`/`itp`/`resa`; agency-owned roles are namespaced
  under the agency code, e.g. `acme.agent`), `label`, `description`, `is_system` (protects built-ins),
  `agency_id` (nullable — NULL = platform-level), `timestamps`, **`softDeletes`**.
- **`permissions`** — `name` (unique, the Gate ability), `module`, `action`, `label`, `description`.
  **No soft delete** (immutable, registry-managed catalog).
- **`permission_role`** / **`role_user`** — M2M pivots (`constrained()->cascadeOnDelete()`, composite PK).
- **`audit_logs`** — `user_id` (nullOnDelete), `event`, `auditable_type`/`auditable_id` (morph),
  `description`, `properties` (json), `ip_address`, `user_agent`, `created_at` (append-only, no updates).
- **`users`** alter — `is_active`, `last_login_at`, **`softDeletes`**, `agency_id` (nullable;
  NULL = platform staff — see [01-agencies.md](01-agencies.md)).
- **`agencies`** (migrations `2026_08_10_00000{1,2}`) — the partner model: main office / outlet / ITP.
  Each agency is an **independent permission scope**; nothing in the authorization layer branches on an
  agency's type or its parent. See [01-agencies.md](01-agencies.md).

Models: `Role` (`hasPermission`), `Permission`, `AuditLog` (`$timestamps=false`, morphs), `User`
(`roles()`, `hasRole`/`hasAnyRole`, `permissionNames()`, **`hasPermissionTo($name, $context=null)`** — the
unused `$context` keeps the signature open for future branch scoping).

## Authorization engine (`app/Services/Rbac/`, `app/Providers/AuthServiceProvider`)

- **`AuthServiceProvider::boot()`** — registers `RolePolicy`/`UserPolicy`, then loops
  `PermissionRegistry::permissionNames()` (config, DB-safe) and `Gate::define($name, fn(User $u) =>
  $u->hasPermissionTo($name))`. Registered in `bootstrap/providers.php`. **No `Gate::before`.**
- **`RbacCache`** — the one seam for the per-user resolved-permission cache (`keyFor`, `remember`,
  `flushUser`, `flushRole`, `flushAll`; TTL `config('rbac.cache_ttl')`, default 1h). `belongsToMany::sync()`
  fires no model events, so services invalidate **explicitly** after any role/permission change.
- **`AuditLogger::log($event, ?$auditable, $properties, ?$description, ?$actor)`** — writes one
  `audit_logs` row (captures actor + request IP/agent). Called by the services and by login/logout.
- **`EnsureUserIsActive`** middleware (appended to the `web` group in `bootstrap/app.php`) — a user
  deactivated mid-session is logged out + redirected to login.
- **Policies** combine the coarse gate with per-instance invariants: `RolePolicy::delete` =
  `can('role.delete') && !$role->is_system`; `UserPolicy::delete/toggleActive` = permission `&&` not self.

## Services (thin controllers, business rules here)

- **`RoleService`** — `create`, `update` (system roles: `name` immutable, `label` editable), `duplicate`
  (copies permissions, forces `is_system=false`, keeps the source's agency), `syncPermissions`
  (→ `RbacCache::flushRole` + audit; capped by the actor's own permissions — see
  [01-agencies.md](01-agencies.md#the-permission-ceiling)), `delete` (soft; guards system +
  last-admin-capable role).
- **`UserAdminService`** — `create` (sets `email_verified_at=now()` so admin-provisioned users clear
  `verified`), `update`, `syncRoles` (→ `flushUser` + audit), `toggleActive`, `resetPassword`, `delete`
  (soft), `adminCapableCount()`.
- **Last-admin guard** — "admin-capable" = holding a role that grants `role.update`; any op dropping the
  count of active, non-trashed admins to 0 throws (controller → `back()->withErrors`). Also blocks
  self-deactivate/-delete. The count is **per scope** (one agency, or the platform scope).

## HTTP surface — `/admin` (`app/Http/Controllers/Admin/`, views `resources/views/admin/`)

Route group: `middleware(['auth','verified'])->prefix('admin')->name('admin.')`.

- **Dashboard** `AdminDashboardController` (`can:admin.access`).
- **Agencies** — index/create/store/edit/update, `toggleActive` (`can:toggleActive,agency`), `destroy`
  (`can:delete,agency`); see [01-agencies.md](01-agencies.md).
- **Users** — index/create/edit/store/update, `toggleActive` (`can:toggleActive,user`), `resetPassword`,
  `destroy` (`can:delete,user`), plus `/{user}/logs` (per-user API calls, `can:apilog.view`).
- **Roles** — index/store, `edit` = permission grid (`rolePermissions` Alpine factory), `update`,
  `syncPermissions`, `duplicate`, `destroy` (`can:delete,role`).
- **Permissions** — read-only catalog grouped by section→module (`can:permission.view`) + "Sync from
  registry" (`can:permission.sync`).
- **Audit Logs** — read-only paginated list (`can:audit.view`).
- **Settings** — TBO environment switch + per-env token TTL/flush (`can:supplier.tbo.manage`); see the
  TBO Air docs.

FormRequests in `app/Http/Requests/Admin/` (`authorize()` → `can(...)`/policy).

## Nav, seeders, registration

- **Sidebar** (`resources/views/layouts/navigation.blade.php`) — two data-driven sections
  (**Travel Operations**, **Administration**) rendered from `PermissionRegistry::navSections(auth()->user())`,
  each item `@can`-gated; icons via `<x-admin.nav-icon>` (`resources/views/components/admin/nav-icon.blade.php`).
- **Seeders** — `PermissionSeeder` (= `sync()`), `RoleSeeder` (`admin`=all, `itp`/`resa`=subsets, all
  `is_system`), `DatabaseSeeder` makes the seeded test user an admin.
- **Public registration is disabled** — the `register` routes are removed from `routes/auth.php`
  (`/register` → 404; the welcome link self-hides).

## Extensibility seams (designed-for, not built)

Modular registry (compose per-module `permissions.php` at the config level — no `PermissionRegistry`
change), feature flags (`enabled` already gates gate/nav/route), version-based cache (swap keys inside
`RbacCache` only), and broader audit coverage (more `AuditLogger::log()` calls, no schema change).

**Branch scoping** is built: an agency defines its own roles, sets their permissions (capped by the
actor's own — no privilege escalation), and creates its own users, seeing nothing outside its scope.
The history tables (**bookings, API logs, audit logs**) carry a stamped `agency_id` and every list that
reads them is scoped by it. `hasPermissionTo`'s `$context` remains a no-op — permissions do not vary by
agency, so `RbacCache` keys are unchanged. See [01-agencies.md](01-agencies.md).

## Tests

`tests/Unit/Rbac/` (permission resolution, registry sync, role-service guards) and
`tests/Feature/Admin/` (authorization route↔permission matrix, user/role management, permission catalog,
deactivated-user logout, nav gating, audit rows); `Auth/RegistrationTest` asserts 404. SQLite `:memory:`
with `RefreshDatabase`; gates come from config so no DB is touched at boot.
