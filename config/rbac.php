<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Permission cache TTL (seconds)
    |--------------------------------------------------------------------------
    | Backstop lifetime for a user's resolved permission set. Invalidation is
    | otherwise explicit (see App\Services\Rbac\RbacCache).
    */
    'cache_ttl' => (int) env('RBAC_CACHE_TTL', 3600),

    /*
    |--------------------------------------------------------------------------
    | Human labels for actions
    |--------------------------------------------------------------------------
    | Used to build the permission label (e.g. "Flights · Search").
    */
    'action_labels' => [
        'access' => 'Access',
        'view' => 'View',
        'create' => 'Create',
        'update' => 'Update',
        'edit' => 'Edit',
        'delete' => 'Delete',
        'search' => 'Search',
        'book' => 'Book',
        'issue' => 'Issue',
        'cancel' => 'Cancel',
        'refund' => 'Refund',
        'sync' => 'Sync',
        'manage' => 'Manage',
        'live' => 'Use Live',
        'request' => 'Request',
        'approve' => 'Approve',
    ],

    /*
    |--------------------------------------------------------------------------
    | Module registry — the single source of truth
    |--------------------------------------------------------------------------
    | Each module is keyed by its machine name. A permission ability is
    | "<module>.<action>" (module may itself contain dots, e.g.
    | "supplier.amadeus" -> "supplier.amadeus.sync"). Naming is singular.
    |
    | Keys per module:
    |   label    - display name
    |   section  - 'administration' | 'travel_operations' (nav + catalog grouping)
    |   group    - optional visual sub-group within a section (e.g. "Suppliers")
    |   route    - named route for the nav link, or null (permission-only stub)
    |   icon     - key resolved by the <x-admin.nav-icon> component
    |   enabled  - feature flag; disabled modules define no gates and are hidden
    |              from nav/routes, but their permission rows still sync so role
    |              assignments survive a toggle (default: true)
    |   actions  - the actions available on the module
    |
    | This array is the only place permissions are declared. It can later be
    | composed from per-module files (Modules/<name>/permissions.php) by merging
    | into this key, with no change to PermissionRegistry.
    */
    'modules' => [

        // ---- Administration -------------------------------------------------
        'admin' => [
            'label' => 'Dashboard',
            'section' => 'administration',
            'route' => 'admin.dashboard',
            'icon' => 'home',
            'actions' => ['access'],
        ],
        'agency' => [
            'label' => 'Agencies',
            'section' => 'administration',
            'route' => 'admin.agencies.index',
            'icon' => 'building',
            'actions' => ['view', 'create', 'update', 'delete'],
        ],
        // An agency member's user and role lists are already scoped to their own
        // agency, which is exactly what the My Agency tabs show — two doors into one
        // room. Platform staff keep both links: theirs are the lists of everyone, and
        // they have no My Agency page to reach them from.
        'user' => [
            'label' => 'Users',
            'section' => 'administration',
            'route' => 'admin.users.index',
            'nav' => 'platform',
            'icon' => 'users',
            'actions' => ['view', 'create', 'update', 'delete'],
        ],
        'role' => [
            'label' => 'Roles',
            'section' => 'administration',
            'route' => 'admin.roles.index',
            'nav' => 'platform',
            'icon' => 'shield',
            'actions' => ['view', 'create', 'update', 'delete'],
        ],
        'permission' => [
            'label' => 'Permissions',
            'section' => 'administration',
            'route' => 'admin.permissions.index',
            'icon' => 'key',
            'actions' => ['view', 'sync'],
        ],
        'audit' => [
            'label' => 'Audit Logs',
            'section' => 'administration',
            'route' => 'admin.audit-logs.index',
            'icon' => 'clipboard',
            'actions' => ['view'],
        ],
        'setting' => [
            'label' => 'Settings',
            'section' => 'administration',
            'route' => 'admin.settings.index',
            'icon' => 'cog',
            'actions' => ['view', 'update'],
        ],

        // ---- Travel Operations ---------------------------------------------
        'flight' => [
            'label' => 'Flights',
            'section' => 'travel_operations',
            'route' => 'flights',
            'icon' => 'airplane',
            'actions' => ['view', 'search', 'book', 'issue'],
        ],
        'hotel' => [
            'label' => 'Hotels',
            'section' => 'travel_operations',
            'route' => 'hotels',
            'icon' => 'building',
            // cancel is the hotel equivalent of the flight money step's aftermath —
            // it moves money back out of a confirmed booking, so it is its own right.
            'actions' => ['view', 'search', 'book', 'cancel'],
        ],
        'booking' => [
            'label' => 'Bookings',
            'section' => 'travel_operations',
            'route' => 'bookings.index',
            'icon' => 'ticket',
            'actions' => ['view', 'create', 'cancel', 'refund'],
        ],
        'supplier.tbo' => [
            'label' => 'TBO',
            'section' => 'travel_operations',
            'group' => 'Suppliers',
            'route' => null,
            'icon' => 'server',
            // manage = edit the TBO environment/cache settings; live = may use the live environment
            'actions' => ['view', 'sync', 'manage', 'live'],
        ],
        'supplier.tbohotel' => [
            'label' => 'TBO Hotel',
            'section' => 'travel_operations',
            'group' => 'Suppliers',
            'route' => 'admin.hotel-catalogue.index',
            'icon' => 'server',
            // sync = refresh the local hotel catalogue (countries, cities, properties);
            // manage = edit the environment/cache settings; live = may use live.
            'actions' => ['view', 'sync', 'manage', 'live'],
            // The supplier's own settings — test/live and the search cache. A second
            // nav entry rather than a second module: same permissions, another door.
            // Admin → Settings is TBO Air's and says nothing about hotels, so without
            // this the page can only be found by opening the catalogue first.
            'links' => [
                ['label' => 'TBO Hotel Settings', 'route' => 'admin.tbo-hotel.settings', 'icon' => 'cog'],
            ],
        ],
        'supplier.amadeus' => [
            'label' => 'Amadeus',
            'section' => 'travel_operations',
            'group' => 'Suppliers',
            'route' => null,
            'icon' => 'server',
            'enabled' => false, // integration not live yet
            'actions' => ['view', 'sync'],
        ],
        'supplier.setting' => [
            'label' => 'Supplier Settings',
            'section' => 'travel_operations',
            'group' => 'Suppliers',
            'route' => null,
            'icon' => 'cog',
            'actions' => ['view', 'update'],
        ],
        'supplier.log' => [
            'label' => 'Supplier Logs',
            'section' => 'travel_operations',
            'group' => 'Suppliers',
            'route' => null,
            'icon' => 'clipboard',
            'actions' => ['view'],
        ],
        // ---- Wallet ---------------------------------------------------------
        // The wallet belongs to the agency, so these permissions say what a member
        // may do with THEIR agency's balance. Who may approve is decided purely by
        // ticking wallet.load.approve on a role — there is no privileged agency type.
        //
        // Both live as tabs on My Agency, so neither needs a sidebar link of its own.
        // The wallet has no page of its own at all: wallet.view gates the tab, which a
        // member opens from the balance in the top bar and platform staff reach through
        // any agency. Load requests keep a link for platform staff, who have no My
        // Agency page and review every agency's queue from there.
        'wallet' => [
            'label' => 'Wallet',
            'section' => 'travel_operations',
            'group' => 'Wallet',
            'route' => null,
            'icon' => 'wallet',
            // adjust = post a manual correction, or reverse an entry made in error.
            // Effectively the right to move money without a request, so it belongs on
            // office/platform roles only.
            'actions' => ['view', 'adjust'],
        ],
        'wallet.load' => [
            'label' => 'Load Requests',
            'section' => 'travel_operations',
            'group' => 'Wallet',
            'route' => 'wallet.requests.index',
            'nav' => 'platform',
            'icon' => 'wallet',
            // create = raise a top-up request; approve = decide it (approve or reject);
            // cancel = withdraw a pending one.
            'actions' => ['view', 'create', 'approve', 'cancel'],
        ],

        'apilog' => [
            'label' => 'API Logs',
            'section' => 'travel_operations',
            'route' => 'api-logs',
            'icon' => 'list',
            'actions' => ['view'],
        ],
        'corporate' => [
            'label' => 'Corporate',
            'section' => 'travel_operations',
            'route' => null,
            'icon' => 'briefcase',
            'actions' => ['view', 'manage'],
        ],
        'itp' => [
            'label' => 'ITP',
            'section' => 'travel_operations',
            'route' => null,
            'icon' => 'briefcase',
            'actions' => ['view', 'manage'],
        ],
        'resa' => [
            'label' => 'Resa',
            'section' => 'travel_operations',
            'route' => null,
            'icon' => 'briefcase',
            'actions' => ['view', 'manage'],
        ],
        'markup' => [
            'label' => 'Markups',
            'section' => 'travel_operations',
            'group' => 'Markups',
            'route' => null,
            'icon' => 'tag',
            'actions' => ['view', 'edit'],
        ],
        'markup.office' => [
            'label' => 'Office Markups',
            'section' => 'travel_operations',
            'group' => 'Markups',
            'route' => null,
            'icon' => 'tag',
            'actions' => ['view', 'edit'],
        ],
    ],
];
