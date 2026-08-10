<x-app-layout>
    <x-slot name="header">
        <x-page-heading title="Agencies" subtitle="Main offices, outlets and ITPs. Each one is an independent scope.">
            <x-slot name="icon">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" />
                </svg>
            </x-slot>
        </x-page-heading>
    </x-slot>

    @can('agency.create')
        <x-slot name="actions">
            <a href="{{ route('admin.agencies.create') }}"
               class="inline-flex shrink-0 items-center gap-2 rounded-lg bg-blue-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                New Agency
            </a>
        </x-slot>
    @endcan

    <x-admin.flash />

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-100 text-sm">
                <thead>
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <th class="px-5 py-3">Agency</th>
                        <th class="px-5 py-3">Type</th>
                        <th class="px-5 py-3">Reports to</th>
                        <th class="px-5 py-3">Users</th>
                        <th class="px-5 py-3">Status</th>
                        <th class="px-5 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($agencies as $agency)
                        <tr class="transition hover:bg-gray-50">
                            <td class="px-5 py-3.5">
                                <div class="flex items-center gap-3">
                                    @if ($agency->logoUrl())
                                        <img src="{{ $agency->logoUrl() }}" alt=""
                                             class="h-9 w-9 shrink-0 rounded-lg object-contain ring-1 ring-gray-200">
                                    @else
                                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-100 text-xs font-semibold text-brand-800">
                                            {{ $agency->initials() }}
                                        </span>
                                    @endif
                                    <div class="min-w-0">
                                        @can('view', $agency)
                                            <a href="{{ route('admin.agencies.show', $agency) }}" class="font-medium text-brand-900 hover:text-blue-600">{{ $agency->name }}</a>
                                        @else
                                            <p class="font-medium text-brand-900">{{ $agency->name }}</p>
                                        @endcan
                                        <p class="font-mono text-xs text-gray-400">{{ $agency->code }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-3.5">
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $agency->type->badgeClasses() }}">
                                    {{ $agency->type->label() }}
                                </span>
                            </td>
                            <td class="px-5 py-3.5 text-gray-500">
                                {{ $agency->parent?->name ?? '—' }}
                            </td>
                            <td class="px-5 py-3.5 text-gray-700">{{ $agency->users_count }}</td>
                            <td class="px-5 py-3.5">
                                @if ($agency->is_active)
                                    <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-600/20">Active</span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-500/20">Inactive</span>
                                @endif
                            </td>
                            <td class="px-5 py-3.5">
                                <div class="flex items-center justify-end gap-2">
                                    @can('view', $agency)
                                        <a href="{{ route('admin.agencies.show', $agency) }}"
                                           class="rounded-md border border-gray-300 bg-white px-2.5 py-1 text-xs font-medium text-gray-700 shadow-sm transition hover:bg-gray-50">View</a>
                                    @endcan
                                    @can('toggleActive', $agency)
                                        <form method="POST" action="{{ route('admin.agencies.toggle-active', $agency) }}">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit"
                                                    class="rounded-md border border-gray-300 bg-white px-2.5 py-1 text-xs font-medium text-gray-700 shadow-sm transition hover:bg-gray-50">
                                                {{ $agency->is_active ? 'Deactivate' : 'Activate' }}
                                            </button>
                                        </form>
                                    @endcan
                                    @can('update', $agency)
                                        <a href="{{ route('admin.agencies.edit', $agency) }}"
                                           class="rounded-md border border-gray-300 bg-white px-2.5 py-1 text-xs font-medium text-blue-600 shadow-sm transition hover:bg-gray-50">
                                            Edit
                                        </a>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-12 text-center">
                                <p class="text-sm font-medium text-brand-900">No agencies yet</p>
                                <p class="mt-1 text-sm text-gray-500">Create a main office, outlet or ITP to start assigning users to it.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($agencies->hasPages())
            <div class="border-t border-gray-100 px-5 py-3">
                {{ $agencies->links() }}
            </div>
        @endif
    </div>
</x-app-layout>
