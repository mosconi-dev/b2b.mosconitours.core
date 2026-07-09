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
        'user' => [
            'label' => 'Users',
            'section' => 'administration',
            'route' => 'admin.users.index',
            'icon' => 'users',
            'actions' => ['view', 'create', 'update', 'delete'],
        ],
        'role' => [
            'label' => 'Roles',
            'section' => 'administration',
            'route' => 'admin.roles.index',
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
            'route' => null, // not built yet — permission stub
            'icon' => 'building',
            'actions' => ['view', 'search', 'book'],
        ],
        'booking' => [
            'label' => 'Bookings',
            'section' => 'travel_operations',
            'route' => null,
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
