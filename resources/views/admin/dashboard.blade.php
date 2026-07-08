<x-app-layout>
    <x-slot name="header">
        <div>
            <h1 class="flex items-center gap-2.5 text-2xl font-bold tracking-tight text-brand-900">
                <svg class="h-7 w-7 text-brand-700" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z" />
                </svg>
                Administration
            </h1>
            <p class="mt-1 text-sm text-gray-500">Manage users, roles, and access across the platform.</p>
        </div>
    </x-slot>

    <!-- Stat cards -->
    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($stats as $stat)
            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <p class="text-sm font-medium text-gray-500">{{ $stat['label'] }}</p>
                <p class="mt-2 text-2xl font-bold tracking-tight text-brand-900">{{ $stat['value'] }}</p>
                <p class="mt-1 text-xs font-medium {{ $stat['tone'] }}">{{ $stat['sub'] }}</p>
            </div>
        @endforeach
    </div>

    <!-- Quick links (appear as each admin module comes online + is permitted) -->
    @php $registry = app(\App\Services\Rbac\PermissionRegistry::class); @endphp
    <div class="mt-8 grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($registry->modules() as $key => $mod)
            @continue($mod['section'] !== 'administration' || empty($mod['route']) || ! \Illuminate\Support\Facades\Route::has($mod['route']))
            @can($registry->primaryAbility($key))
                <a href="{{ route($mod['route']) }}"
                   class="group flex items-center justify-between rounded-xl border border-gray-200 bg-white p-5 shadow-sm transition hover:border-brand-300 hover:shadow">
                    <div>
                        <p class="text-base font-semibold text-brand-900">{{ $mod['label'] }}</p>
                        <p class="mt-0.5 text-sm text-gray-500">Manage {{ \Illuminate\Support\Str::lower($mod['label']) }}</p>
                    </div>
                    <svg class="h-5 w-5 text-gray-300 transition group-hover:text-brand-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                    </svg>
                </a>
            @endcan
        @endforeach
    </div>
</x-app-layout>
