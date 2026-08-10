# Agencies — the partner model

A partner record: a **main office**, an **outlet**, or an **ITP**. Namespace: `App\Services\Rbac`
(it sits beside `RoleService`/`UserAdminService` because an agency is the scope those operate within).

## The one rule

**Every agency is an independent permission scope.** A user belongs to exactly one agency, and what
they may do there comes only from the roles assigned to them. Nothing in the authorization layer
branches on an agency's `type` or its position in the `parent_id` tree.

That means "the main office superadmin can do more than the outlet admin" is **data, not code**: it is
true because the superadmin role holds more permissions, not because `main_office` is privileged. Both
statements below are enforced by `AgencyManagementTest::test_agency_type_does_not_affect_permissions`.

Deliberately **not** built, and why:

| Rejected | Reason |
| --- | --- |
| Permission inheritance down the `parent_id` tree | Main office is an individual partner, not an HQ — it holds no implicit authority over its outlets |
| `access_scope` (self / agency / subtree / global) on a grant | Scope is always "your agency"; a subtree scope only exists if inheritance does |
| `roles.scope_type` (which agency types may use a role) | Any role is assignable at any agency; the constraint would encode the type-based rules we just rejected |
| `role_user.agency_id` | One user = one agency, so the pivot column would always equal `users.agency_id` |

The upgrade path if a user ever needs to work in two branches is to add `agency_id` to `role_user` and
treat `users.agency_id` as the default. Nothing here blocks that.

## Data model (migrations `2026_08_10_00000{1,2}`)

- **`agencies`** — `code` (unique, **immutable** once issued: reports and exports reference it), `name`,
  `type` (`AgencyType`: `main_office` \| `outlet` \| `itp`), `parent_id` (nullable self-FK,
  `restrictOnDelete`), `is_active`, `timestamps`, **`softDeletes`**; plus the profile fields from
  migration `2026_08_10_000005` — `contact_email`, `contact_phone`, `address`, `logo_path`.
- **`users`** alter — `agency_id` (nullable FK, **`restrictOnDelete`**).
- **`roles`** alter (migration `2026_08_10_000003`) — `agency_id` (nullable FK, **`cascadeOnDelete`**;
  NULL = a platform-level role). Roles are artifacts of their agency, unlike users, who are people —
  hence cascade here and restrict there.

`parent_id` records which office an agency reports to, for **reporting and markups only**. It grants
nothing. `restrictOnDelete` on both FKs is deliberate: `nullOnDelete` on `users.agency_id` would
silently widen a member's scope to platform staff when their agency was hard-deleted.

**`agency_id = NULL` means platform staff** — not bound to any agency, so no agency scope applies
(`User::isPlatformStaff()`). This is a *row-scope* exemption, not a permission bypass: platform staff
still need the ability like everyone else, and there is still no `Gate::before`.

## Model & enum

`Agency` — `users()`, `parent()`, `children()`, `scopeActive()`, `label()` ("Name (code)").
`AgencyType` — `label()`, `badgeClasses()`, `values()`; carries no authorization meaning by design.

## `AgencyService`

`create` (code from `Str::slug($code ?: $name)`, de-duplicated against trashed rows), `update` (name,
type and parent; **code is immutable**), `toggleActive`, `delete` (soft). It holds no authorization
logic and never reads or writes permissions.

Guards:
- **delete** — refused while the agency has any users, or any agencies reporting to it.
- **parent** — an agency cannot report to itself or to one of its own descendants (walks the chain,
  and tolerates a pre-existing cycle rather than hanging).

`AgencyPolicy` adds the self-action guards: you cannot deactivate or delete **your own** agency.

Audit events: `agency.created`, `agency.updated`, `agency.activated`, `agency.deactivated`,
`agency.deleted`. Moving a user between agencies is recorded on `user.updated` as
`agency_from`/`agency_to`, because it changes the scope everything they do is filed under.

## HTTP surface

Registry module `agency` (section `administration`, route `admin.agencies.index`, icon `building`,
actions view/create/update/delete). Routes: index, create, store, **show**, edit, update,
`toggle-active` (`can:toggleActive,agency`), `destroy` (`can:delete,agency`). Views in
`resources/views/admin/agencies/` (`index`, `show`, `create`, `edit`, `_form`); the user forms gain
`admin/users/_agency.blade.php` and the user list shows an agency column.

**`show` is the agency hub** (`can:view,agency`) — the only place that answers "who and what belongs to
this agency". A summary strip (type, reports-to, user count, role count) over two tabs: **Users** in the
agency and **Roles** it owns, each row linking to the existing edit screens, with Edit and
Activate/Deactivate in the page actions. Both lists are additionally passed through `visibleTo()`, so a
scoped viewer can never be shown a row the rest of the admin area would hide.

`GET /{agency}` is registered **after** `/create` and constrained with `whereNumber`, so the literal
segment keeps winning the match — `AgencyManagementTest` pins this.

Note that no other Admin controller has a `show()`; users and roles go list → edit. Agencies earn one
because they aggregate two other resources.

## Profile — contact details and logo

`contact_email`, `contact_phone` and `address` are plain optional fields. The logo is a file on the
**`public` disk** under `agency-logos/`; `logo_path` stores the path (not a URL) so the disk or domain
can change without rewriting rows. `Agency::logoUrl()` builds the URL, `Agency::initials()` is the
fallback shown when there is none. **This is the app's first upload — it needs `php artisan storage:link`
on any new environment.**

Upload rules (`StoreAgencyRequest::logoRules()`, shared with the update request):

- **JPG / PNG / WEBP only, max 2MB, max 4000×4000.** SVG is deliberately excluded — it is a document
  that can carry script, and these files are served from our own origin.
- Stored under `Str::random(40)` with the extension derived from the **validated MIME type**, never from
  the uploaded filename, which is attacker-controlled. A test asserts a `../../evil shell.png` upload
  lands as `agency-logos/<40 chars>.png`.
- Replacing a logo deletes the previous file; `remove_logo=1` clears it. A details-only edit leaves it
  alone. Soft-deleting an agency **keeps** the file, so the archived record still renders.

The picker (`admin/agencies/_logo-dropzone.blade.php` + the `logoDropzone` Alpine component) is
drag-and-drop with click-to-browse, live preview and client-side type/size checks. It drives a real
`<input type="file">` by assigning the dropped file through a `DataTransfer`, so the form submits as a
normal multipart POST and still works if Alpine never boots. Client-side checks are convenience only —
the server rules above are the ones that decide. Both agency forms carry `enctype="multipart/form-data"`.

## Self-administration — an agency runs itself

An agency defines its own roles, sets their permissions, and creates its own users. Two columns carry
the whole model: `roles.agency_id` (NULL = a platform-level role) and `users.agency_id`.

**The scope invariant:** a user may only hold roles from their own scope — `role.agency_id ===
user.agency_id`. An agency's role can never land on another agency's people, nor on platform staff, nor
a platform role on an agency member. Enforced in `UserAdminService::guardRolesAreInScope()`, which every
role assignment funnels through. Moving a user between agencies therefore **drops** the roles they held
at the old one (`rolesWithinScope()`); the change only ever removes access.

**Ownership is forced, not validated.** `RoleService::resolveOwner()` and
`UserAdminService::resolveAgency()` *ignore* a submitted `agency_id` when the actor belongs to an
agency, substituting the actor's own. A forged form field cannot plant a role or a person in someone
else's agency — there is nothing to bypass, because the value is never read.

**Machine names stay globally unique**, so an agency's roles are namespaced under its code: Acme's
"Agent" is `acme.agent`, Rival's is `rival.agent`. `roles.name` keeps its unique index and every
existing seeder/test lookup keeps working.

### The permission ceiling

An agency member may only put permissions on a role that **they hold themselves**
(`RoleService::boundToCeiling()`). Without this, an agency admin holding `role.create` could mint a role
granting anything and assign it to themselves. Platform staff are unbounded — they operate the system,
and capping them would make some permissions ungrantable by anyone.

Two consequences worth knowing:

- The permission grid on the role edit page is **capped to the actor's own permissions**, so the UI
  never offers a checkbox the service would refuse.
- Because the grid is capped, a naive save would silently strip grants the actor cannot see. It does
  not: permissions already on the role but outside the actor's ceiling are **preserved**, and listed
  read-only under "Granted beyond your own access" (`unmanageablePermissionLabels()`). Only a request to
  *add* something outside the ceiling — one the role does not already hold — is rejected.

### Why there is no separate "my agency" permission

The obvious-looking addition — a second module (`myagency.*`) so a member can reach their own agency
without seeing the network — was **rejected**. `agency.view` already means "may look at agency records";
*which* records is a scope question, decided by `agency_id`, exactly as it is for users and roles. A
parallel module would duplicate the ability, and half its actions would be meaningless anyway: you do
not `create` your own agency, and `delete` on it is already blocked by the self-guard.

What the distinction actually needs is scoping, not a new tick-box:

- `Agency::scopeVisibleTo()` filters the index — platform staff see the whole network, a member sees
  only their own row.
- `parentOptions()` is scoped too, so the "Reports to" dropdown never becomes a directory of every
  partner. For a member that leaves only their own agency, which is excluded — so they cannot re-parent.
- The nav item resolves per viewer (`PermissionRegistry::navTarget()`): platform staff get **Agencies**
  → the index; a member gets **My Agency** → straight to their own show page, since a list of exactly
  one row is pointless.
- The **back link follows the same logic**: a member has no list to return to, so `show` renders none
  (their agency page is the top of their tree) and `edit` points back to the agency instead.
- **`agency.view` does not imply `user.view` or `role.view`.** The show page's tabs, and the matching
  count tiles, each require their own permission; the open tab falls back to whichever the viewer holds,
  and with neither the page shows the summary only. Otherwise `agency.view` alone would leak the whole
  staff roster — the same class of bug as the unscoped index.

Grant an agency admin `agency.view` to let them see their own hub, and add `agency.update` if they may
rename it. Withholding both simply hides the module from them.

### Scoped surfaces

`Role::scopeVisibleTo()` / `User::scopeVisibleTo()` filter the admin lists; `RolePolicy` and
`UserPolicy` add the matching per-instance checks, so `can:update,role` and `can:update,user` return 403
across agency boundaries. `UpdateUserRequest` and the `/{user}/edit`, `/{user}/logs`, `/{role}/edit`
routes were switched from the bare permission to the policy for exactly this reason.

The **last-admin guard is per scope**: `adminCapableCount(?int $agencyId)` counts within one agency, or
within the platform scope when null. An agency losing its last admin is recoverable — platform staff can
step in — whereas the platform losing its last admin is not.

### Provisioning from inside the agency

The show page carries **New User** and **New Role** buttons (contextual to the open tab). Both post to
routes nested under the agency, so the flow never leaves `/admin/agencies/{agency}`:

| Route | Name |
| --- | --- |
| `GET/POST /admin/agencies/{agency}/users[/create]` | `admin.agencies.users.create` / `.store` |
| `GET/POST /admin/agencies/{agency}/roles[/create]` | `admin.agencies.roles.create` / `.store` |

The agency is **the route, not a form field** — `StoreAgencyUserRequest`/`StoreAgencyRoleRequest` carry
no `agency_id`, so there is nothing to forge. Each is guarded twice: the ability (`user.create` /
`role.create`) **and** `can:view,agency`, which is what stops an agency admin provisioning into someone
else's agency.

Two differences from the global screens, both deliberate:

- The **user form offers only roles this agency owns** (the scope invariant), and says so plainly when
  the agency owns none yet.
- The **role form carries the permission grid inline**, so one submit creates the role *and* sets its
  permissions. The global screen instead creates first and redirects to the grid — which would leave the
  agency URL. The grid markup is shared via `admin/roles/_permission-grid.blade.php`, built by
  `PermissionRegistry::grid($actor)` (moved there from `RoleController` so both screens use one source).

### Writes are transactional

`RoleService::create` and `UserAdminService::create`/`update` run inside `DB::transaction`. Their guards
(ceiling, role scope, last-admin) throw *after* the row would have been written, so without this a
rejected request left an orphan behind — a permission-less role, or worse, a role-less user account with
a working login. `AgencyScopedAdministrationTest` pins all three rollbacks.

### Bootstrapping a new agency

Platform staff create the agency, then use the same two buttons on its show page to add the first role
and the first user. From there the agency admin is self-sufficient. `duplicate` deliberately keeps the
copy in the **source's** agency, so it cannot be used to move permissions across scopes.

## History tables (migration `2026_08_10_000004`)

`bookings`, `audit_logs` and `tbo_air_api_logs` each carry a **denormalized `agency_id`, stamped at
creation** — never resolved through `users.agency_id` at read time, so a user who transfers agencies
does not drag their booking and log history into the new one. The migration backfills existing rows from
their actor.

`App\Models\Concerns\BelongsToAgency` gives all three `agency()`, `scopeVisibleTo()` and
`isVisibleTo()`. A NULL `agency_id` (a platform-staff action) falls outside every member's scope, so it
**fails closed**. `nullOnDelete` here, unlike `users.agency_id`'s `restrictOnDelete`: losing attribution
on a history row beats blocking the delete, and a nulled row simply becomes platform-only.

Stamped at: `BookingService::createFromQuote` (from the booker), `TboAirClient::record` (from the
authenticated user), `AuditLogger::log` (from the actor).

Two judgement calls worth knowing:

- **Audit rows are scoped by the ACTOR's agency** — an agency's trail is what its own people did. A
  platform-staff action on an agency's records stamps NULL and so stays invisible to that agency. Scoping
  by the *subject* would show more, but the subject is polymorphic; this direction over-hides rather than
  over-shares.
- **Bookings were never leaking** — `BookingController` already filtered to the booker's own rows. The
  agency scope is applied *on top of* ownership, which is redundant today but keeps the list correct if
  visibility is ever widened to "everyone in my agency". `ApiLogController::show` by contrast had **no**
  per-row check at all and now has one.

## Not yet built

Bookings are still visible only to the user who made them. Letting an agency admin see their whole
agency's bookings is a product decision, not a bug — the `agency_id` and `visibleTo()` needed for it are
already in place, so it is a one-line change to `BookingController::index` when wanted.
