<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="flex items-center gap-2.5 text-2xl font-bold tracking-tight text-brand-900">
                    <svg class="h-7 w-7 text-brand-700" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z" />
                    </svg>
                    Permissions
                </h1>
                <p class="mt-1 text-sm text-gray-500">{{ $total }} permissions, declared by the module registry. Assign them via roles.</p>
            </div>
            @can('permission.sync')
                <form method="POST" action="{{ route('admin.permissions.sync') }}" class="shrink-0">
                    @csrf
                    <button type="submit"
                            class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3.5 py-2 text-sm font-semibold text-gray-700 shadow-sm transition hover:bg-gray-50">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
                        </svg>
                        Sync from Registry
                    </button>
                </form>
            @endcan
        </div>
    </x-slot>

    <x-admin.flash />

    <div class="space-y-8">
        @foreach (['administration', 'travel_operations'] as $sectionKey)
            @php $modules = $sections[$sectionKey] ?? []; @endphp
            @continue(empty($modules))
            <div>
                <h2 class="mb-3 text-[11px] font-semibold uppercase tracking-wider text-gray-400">{{ $sectionLabels[$sectionKey] }}</h2>
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                    @foreach ($modules as $module)
                        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                            <div class="flex items-center gap-2">
                                <h3 class="text-sm font-semibold text-brand-900">{{ $module['label'] }}</h3>
                                @unless ($module['enabled'])
                                    <span class="inline-flex items-center rounded-full bg-gray-100 px-1.5 py-0.5 text-[10px] font-medium text-gray-500 ring-1 ring-inset ring-gray-500/20">Disabled</span>
                                @endunless
                            </div>
                            <ul class="mt-3 space-y-1.5">
                                @foreach ($module['permissions'] as $perm)
                                    <li class="flex items-center justify-between gap-2">
                                        <span class="font-mono text-xs text-gray-600">{{ $perm['name'] }}</span>
                                        <span class="inline-flex items-center rounded-full bg-gray-50 px-1.5 py-0.5 text-[10px] font-medium text-gray-500 ring-1 ring-inset ring-gray-500/20"
                                              title="{{ $perm['roles_count'] }} role(s) grant this">{{ $perm['roles_count'] }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
</x-app-layout>
