@php
    use Illuminate\Support\Str;

    // Shared link styles for the navy sidebar
    $linkBase   = 'group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors';
    $linkIdle   = 'text-white/60 hover:bg-white/10 hover:text-white';
    $linkActive = 'bg-white/10 text-white';

    // Permission-driven navigation, grouped by section (Travel Operations / Administration).
    $navSections = app(\App\Services\Rbac\PermissionRegistry::class)->navSections(auth()->user());
    $sectionLabels = ['travel_operations' => 'Travel Operations', 'administration' => 'Administration'];

    $isActive = fn (string $route): bool => request()->routeIs($route)
        || request()->routeIs(Str::of($route)->beforeLast('.')->append('.*')->value());
@endphp

<aside x-cloak
       :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
       class="fixed inset-y-0 left-0 z-40 flex w-64 transform flex-col bg-brand-800 transition-transform duration-200 ease-in-out lg:translate-x-0">

    <!-- Brand -->
    <div class="flex h-16 shrink-0 items-center justify-between gap-2 border-b border-white/10 px-4">
        <a href="{{ route('dashboard') }}" class="flex items-center gap-2.5">
            <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-accent text-sm font-extrabold tracking-tight text-brand-900">PX</span>
            <span class="flex flex-col leading-tight">
                <span class="text-sm font-semibold text-white">Mosconi Tours</span>
                <span class="text-[11px] text-white/50">B2B Portal</span>
            </span>
        </a>
        <button @click="sidebarOpen = false" class="rounded-md p-1.5 text-white/60 hover:bg-white/10 hover:text-white lg:hidden">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>
    </div>

    <!-- Quick search -->
    <div class="px-3 pt-4">
        <div class="relative">
            <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-white/40">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                </svg>
            </span>
            <input type="text" placeholder="Quick search..."
                   class="w-full rounded-lg border-white/10 bg-white/10 py-2 pl-9 pr-12 text-sm text-white placeholder-white/50 focus:border-accent/60 focus:bg-white/15 focus:ring-0" />
            <span class="pointer-events-none absolute inset-y-0 right-2.5 flex items-center">
                <kbd class="rounded border border-white/15 bg-white/5 px-1.5 py-0.5 text-[10px] font-medium text-white/50">⌘K</kbd>
            </span>
        </div>
    </div>

    <!-- Navigation -->
    <nav class="sidebar-scroll flex-1 space-y-6 overflow-y-auto px-3 py-4">

        <!-- Home -->
        <div class="space-y-1">
            <a href="{{ route('dashboard') }}"
               class="{{ $linkBase }} {{ request()->routeIs('dashboard') ? $linkActive : $linkIdle }}">
                <x-admin.nav-icon name="home" :active="request()->routeIs('dashboard')" />
                Dashboard
            </a>
        </div>

        <!-- Permission-driven sections -->
        @foreach (['travel_operations', 'administration'] as $sectionKey)
            @php $items = $navSections[$sectionKey] ?? []; @endphp
            @if (! empty($items))
                <div>
                    <p class="px-3 pb-1.5 text-[11px] font-semibold uppercase tracking-wider text-white/35">{{ $sectionLabels[$sectionKey] }}</p>
                    <div class="space-y-1">
                        @foreach ($items as $item)
                            @php $active = $isActive($item['route']); @endphp
                            <a href="{{ route($item['route']) }}"
                               class="{{ $linkBase }} {{ $active ? $linkActive : $linkIdle }}">
                                <x-admin.nav-icon :name="$item['icon']" :active="$active" />
                                {{ $item['label'] }}
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif
        @endforeach

        <!-- Account -->
        <div>
            <p class="px-3 pb-1.5 text-[11px] font-semibold uppercase tracking-wider text-white/35">Account</p>
            <div class="space-y-1">
                <a href="{{ route('profile.edit') }}"
                   class="{{ $linkBase }} {{ request()->routeIs('profile.*') ? $linkActive : $linkIdle }}">
                    <svg class="h-5 w-5 {{ request()->routeIs('profile.*') ? 'text-accent' : 'text-white/50 group-hover:text-white' }}" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
                    </svg>
                    Profile
                </a>
            </div>
        </div>
    </nav>

    <!-- User card -->
    <div class="shrink-0 border-t border-white/10 p-3">
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <div class="flex items-center gap-3 rounded-lg px-2 py-1.5">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-white/10 text-sm font-semibold text-white">
                    {{ strtoupper(Str::substr(Auth::user()->name ?? 'U', 0, 1)) }}
                </span>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium text-white">{{ Auth::user()->name }}</p>
                    <p class="truncate text-[11px] text-white/50">{{ Auth::user()->email }}</p>
                </div>
                <button type="submit" title="{{ __('Log Out') }}"
                        class="rounded-md p-1.5 text-white/50 hover:bg-white/10 hover:text-white">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75" />
                    </svg>
                </button>
            </div>
        </form>
    </div>
</aside>
