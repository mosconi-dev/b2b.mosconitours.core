<x-app-layout>
    <x-slot name="header">
        <x-page-heading :title="$agency->name" :subtitle="$agency->code">
            <x-slot name="icon">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" />
                </svg>
            </x-slot>
            <span class="inline-flex shrink-0 items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $agency->type->badgeClasses() }}">
                {{ $agency->type->label() }}
            </span>
            @unless ($agency->is_active)
                <span class="inline-flex shrink-0 items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-500/20">Inactive</span>
            @endunless
        </x-page-heading>
    </x-slot>

    {{-- Only platform staff have an agencies list to go back to. For a member this
         page IS their root ("My Agency" in the nav), so there is nothing above it. --}}
    @if (auth()->user()->isPlatformStaff())
        <x-slot name="back">
            <a href="{{ route('admin.agencies.index') }}" class="text-sm font-medium text-gray-500 hover:text-gray-700">&larr; Back to agencies</a>
        </x-slot>
    @endif

    <x-slot name="actions">
        {{-- Contextual to the open tab, so the primary action always matches what you are looking at. --}}
        @if ($tab === 'roles')
            @can('role.create')
                <a href="{{ route('admin.agencies.roles.create', $agency) }}"
                   class="inline-flex shrink-0 items-center gap-2 rounded-lg border border-gray-300 bg-white px-3.5 py-2 text-sm font-semibold text-gray-700 shadow-sm transition hover:bg-gray-50">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    New Role
                </a>
            @endcan
        @else
            @can('user.create')
                <a href="{{ route('admin.agencies.users.create', $agency) }}"
                   class="inline-flex shrink-0 items-center gap-2 rounded-lg border border-gray-300 bg-white px-3.5 py-2 text-sm font-semibold text-gray-700 shadow-sm transition hover:bg-gray-50">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    New User
                </a>
            @endcan
        @endif

        @can('toggleActive', $agency)
            <form method="POST" action="{{ route('admin.agencies.toggle-active', $agency) }}">
                @csrf
                @method('PATCH')
                <button type="submit"
                        class="inline-flex shrink-0 items-center gap-2 rounded-lg border border-gray-300 bg-white px-3.5 py-2 text-sm font-semibold text-gray-700 shadow-sm transition hover:bg-gray-50">
                    {{ $agency->is_active ? 'Deactivate' : 'Activate' }}
                </button>
            </form>
        @endcan
        @can('update', $agency)
            <a href="{{ route('admin.agencies.edit', $agency) }}"
               class="inline-flex shrink-0 items-center gap-2 rounded-lg bg-blue-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700">
                Edit
            </a>
        @endcan
    </x-slot>

    <div class="space-y-6">
        <x-admin.flash />

        {{-- Identity + contact --}}
        <div class="flex flex-col gap-5 rounded-xl border border-gray-200 bg-white p-6 shadow-sm sm:flex-row sm:items-start">
            @if ($agency->logoUrl())
                <img src="{{ $agency->logoUrl() }}" alt="{{ $agency->name }} logo"
                     class="h-20 w-20 shrink-0 rounded-lg object-contain ring-1 ring-gray-200">
            @else
                <span class="flex h-20 w-20 shrink-0 items-center justify-center rounded-lg bg-brand-100 text-xl font-semibold text-brand-800">
                    {{ $agency->initials() }}
                </span>
            @endif

            <dl class="grid min-w-0 flex-1 grid-cols-1 gap-4 sm:grid-cols-3">
                <div class="min-w-0">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Email</dt>
                    <dd class="mt-1 truncate text-sm text-gray-700">
                        @if ($agency->contact_email)
                            <a href="mailto:{{ $agency->contact_email }}" class="text-blue-600 hover:underline">{{ $agency->contact_email }}</a>
                        @else
                            <span class="text-gray-400">—</span>
                        @endif
                    </dd>
                </div>
                <div class="min-w-0">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Contact number</dt>
                    <dd class="mt-1 truncate text-sm text-gray-700">
                        @if ($agency->contact_phone)
                            <a href="tel:{{ $agency->contact_phone }}" class="text-blue-600 hover:underline">{{ $agency->contact_phone }}</a>
                        @else
                            <span class="text-gray-400">—</span>
                        @endif
                    </dd>
                </div>
                <div class="min-w-0">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Address</dt>
                    <dd class="mt-1 whitespace-pre-line text-sm text-gray-700">{{ $agency->address ?: '—' }}</dd>
                </div>
            </dl>
        </div>

        {{-- Summary --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @php
                // Counts follow the same permissions as the tabs below them.
                $facts = array_values(array_filter([
                    ['label' => 'Type', 'value' => $agency->type->label()],
                    ['label' => 'Reports to', 'value' => $agency->parent?->name ?? 'None (independent)'],
                    in_array('users', $tabs, true) ? ['label' => 'Users', 'value' => $agency->users_count] : null,
                    in_array('roles', $tabs, true) ? ['label' => 'Roles', 'value' => $agency->roles_count] : null,
                ]));
            @endphp
            @foreach ($facts as $fact)
                <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $fact['label'] }}</p>
                    <p class="mt-1 text-base font-semibold text-brand-900">{{ $fact['value'] }}</p>
                </div>
            @endforeach
        </div>

        {{-- Tabs — only those the viewer holds the permission for. --}}
        @if (count($tabs) > 1)
            <div class="inline-flex rounded-lg border border-gray-200 bg-white p-1 shadow-sm">
                <a href="{{ route('admin.agencies.show', $agency) }}"
                   @class([
                       'rounded-md px-3.5 py-1.5 text-sm font-medium transition',
                       'bg-brand-800 text-white shadow-sm' => $tab === 'users',
                       'text-gray-500 hover:text-gray-700' => $tab !== 'users',
                   ])>Users</a>
                <a href="{{ route('admin.agencies.show', ['agency' => $agency, 'tab' => 'roles']) }}"
                   @class([
                       'rounded-md px-3.5 py-1.5 text-sm font-medium transition',
                       'bg-brand-800 text-white shadow-sm' => $tab === 'roles',
                       'text-gray-500 hover:text-gray-700' => $tab !== 'roles',
                   ])>Roles</a>
            </div>
        @endif

        @if ($tab === null)
            <div class="rounded-xl border border-gray-200 bg-white p-12 text-center shadow-sm">
                <p class="text-sm font-medium text-brand-900">Nothing more to show</p>
                <p class="mt-1 text-sm text-gray-500">You do not have permission to view this agency's users or roles.</p>
            </div>
        @elseif ($tab === 'roles')
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                @if ($roles->isEmpty())
                    <div class="p-12 text-center">
                        <p class="text-sm font-medium text-brand-900">No roles yet</p>
                        <p class="mt-1 text-sm text-gray-500">
                            This agency owns no roles, so nobody here can be given one yet.
                        </p>
                        @can('role.create')
                            <a href="{{ route('admin.agencies.roles.create', $agency) }}"
                               class="mt-4 inline-flex items-center gap-2 rounded-lg bg-blue-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700">
                                New Role
                            </a>
                        @endcan
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-100 text-sm">
                            <thead>
                                <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                    <th class="px-5 py-3">Role</th>
                                    <th class="px-5 py-3">Members</th>
                                    <th class="px-5 py-3">Permissions</th>
                                    <th class="px-5 py-3 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($roles as $role)
                                    <tr class="transition hover:bg-gray-50">
                                        <td class="px-5 py-3.5">
                                            <p class="font-medium text-brand-900">{{ $role->label }}</p>
                                            <p class="font-mono text-xs text-gray-400">{{ $role->name }}</p>
                                        </td>
                                        <td class="px-5 py-3.5 text-gray-700">{{ $role->users_count }}</td>
                                        <td class="px-5 py-3.5 text-gray-700">{{ $role->permissions_count }}</td>
                                        <td class="px-5 py-3.5 text-right">
                                            @can('update', $role)
                                                <a href="{{ route('admin.roles.edit', $role) }}"
                                                   class="rounded-md border border-gray-300 bg-white px-2.5 py-1 text-xs font-medium text-blue-600 shadow-sm transition hover:bg-gray-50">Edit</a>
                                            @endcan
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($roles->hasPages())
                        <div class="border-t border-gray-100 px-5 py-3">{{ $roles->links() }}</div>
                    @endif
                @endif
            </div>
        @else
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                @if ($users->isEmpty())
                    <div class="p-12 text-center">
                        <p class="text-sm font-medium text-brand-900">No users yet</p>
                        <p class="mt-1 text-sm text-gray-500">Nobody belongs to this agency.</p>
                        @can('user.create')
                            <a href="{{ route('admin.agencies.users.create', $agency) }}"
                               class="mt-4 inline-flex items-center gap-2 rounded-lg bg-blue-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700">
                                New User
                            </a>
                        @endcan
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-100 text-sm">
                            <thead>
                                <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                    <th class="px-5 py-3">User</th>
                                    <th class="px-5 py-3">Roles</th>
                                    <th class="px-5 py-3">Status</th>
                                    <th class="px-5 py-3">Last login</th>
                                    <th class="px-5 py-3 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($users as $u)
                                    <tr class="transition hover:bg-gray-50">
                                        <td class="px-5 py-3.5">
                                            <div class="flex items-center gap-3">
                                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-100 text-sm font-semibold text-brand-800">
                                                    {{ strtoupper(\Illuminate\Support\Str::substr($u->name, 0, 1)) }}
                                                </span>
                                                <div class="min-w-0">
                                                    <p class="font-medium text-brand-900">{{ $u->name }}</p>
                                                    <p class="truncate text-xs text-gray-500">{{ $u->email }}</p>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-5 py-3.5">
                                            <div class="flex flex-wrap gap-1">
                                                @forelse ($u->roles as $role)
                                                    <span class="inline-flex items-center rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700 ring-1 ring-inset ring-indigo-600/20">{{ $role->label }}</span>
                                                @empty
                                                    <span class="text-xs text-gray-400">No roles</span>
                                                @endforelse
                                            </div>
                                        </td>
                                        <td class="px-5 py-3.5">
                                            @if ($u->is_active)
                                                <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-600/20">Active</span>
                                            @else
                                                <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-500/20">Inactive</span>
                                            @endif
                                        </td>
                                        <td class="px-5 py-3.5 text-gray-500">{{ $u->last_login_at?->diffForHumans() ?? '—' }}</td>
                                        <td class="px-5 py-3.5 text-right">
                                            @can('update', $u)
                                                <a href="{{ route('admin.users.edit', $u) }}"
                                                   class="rounded-md border border-gray-300 bg-white px-2.5 py-1 text-xs font-medium text-blue-600 shadow-sm transition hover:bg-gray-50">Edit</a>
                                            @endcan
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($users->hasPages())
                        <div class="border-t border-gray-100 px-5 py-3">{{ $users->links() }}</div>
                    @endif
                @endif
            </div>
        @endif
    </div>
</x-app-layout>
