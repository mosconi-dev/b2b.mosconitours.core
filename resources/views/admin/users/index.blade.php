<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="flex items-center gap-2.5 text-2xl font-bold tracking-tight text-brand-900">
                    <svg class="h-7 w-7 text-brand-700" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                    </svg>
                    Users
                </h1>
                <p class="mt-1 text-sm text-gray-500">Create users, assign roles, and manage access.</p>
            </div>
            @can('user.create')
                <a href="{{ route('admin.users.create') }}"
                   class="inline-flex shrink-0 items-center gap-2 rounded-lg bg-blue-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    New User
                </a>
            @endcan
        </div>
    </x-slot>

    <x-admin.flash />

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
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
                    @forelse ($users as $u)
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
                                        <span class="inline-flex items-center rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700 ring-1 ring-inset ring-indigo-600/20">
                                            {{ $role->label }}
                                        </span>
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
                            <td class="px-5 py-3.5 text-gray-500">
                                {{ $u->last_login_at?->diffForHumans() ?? '—' }}
                            </td>
                            <td class="px-5 py-3.5">
                                <div class="flex items-center justify-end gap-2">
                                    @can('toggleActive', $u)
                                        <form method="POST" action="{{ route('admin.users.toggle-active', $u) }}">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit"
                                                    class="rounded-md border border-gray-300 bg-white px-2.5 py-1 text-xs font-medium text-gray-700 shadow-sm transition hover:bg-gray-50">
                                                {{ $u->is_active ? 'Deactivate' : 'Activate' }}
                                            </button>
                                        </form>
                                    @endcan
                                    @can('user.update')
                                        <a href="{{ route('admin.users.edit', $u) }}"
                                           class="rounded-md border border-gray-300 bg-white px-2.5 py-1 text-xs font-medium text-blue-600 shadow-sm transition hover:bg-gray-50">
                                            Edit
                                        </a>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-5 py-12 text-center">
                                <p class="text-sm font-medium text-brand-900">No users yet</p>
                                <p class="mt-1 text-sm text-gray-500">Create your first user to get started.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($users->hasPages())
            <div class="border-t border-gray-100 px-5 py-3">
                {{ $users->links() }}
            </div>
        @endif
    </div>
</x-app-layout>
