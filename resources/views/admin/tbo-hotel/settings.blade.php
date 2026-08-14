<x-app-layout>
    <x-slot name="header">
        <x-page-heading title="TBO Hotel Settings"
                        subtitle="Which TBO Holidays environment this platform talks to.">
            <x-slot name="icon">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.281z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
            </x-slot>
        </x-page-heading>
    </x-slot>

    @include('admin.tbo-hotel._tabs')

    <x-admin.flash />

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3 lg:items-start">

        {{-- Environment --}}
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm lg:col-span-2">
            <div class="flex items-center gap-2">
                <h2 class="text-sm font-semibold text-brand-900">Environment</h2>
                @if ($effectiveEnvironment === 'live')
                    <span class="inline-flex items-center gap-1 rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-semibold uppercase tracking-wide text-red-700 ring-1 ring-inset ring-red-600/30">
                        <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-red-500"></span> Live
                    </span>
                @else
                    <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold uppercase tracking-wide text-emerald-700 ring-1 ring-inset ring-emerald-600/20">
                        Test
                    </span>
                @endif
            </div>

            <p class="mt-1 text-sm text-gray-500">
                Hotel calls from <span class="font-semibold text-brand-900">your</span> account currently go to
                <span class="font-semibold text-brand-900">{{ $effectiveEnvironment }}</span>.
            </p>

            @if ($effectiveEnvironment === 'live')
                <div class="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                    <strong>Live mode is active.</strong> Searches, PreBook and Book hit production TBO and can
                    create real, billable reservations.
                </div>
            @endif

            {{-- Flights on live while hotels are on test is a normal state to be in, and a
                 bad one to be in without being told. --}}
            @if ($airEnvironment !== $effectiveEnvironment)
                <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    <strong>Suppliers disagree.</strong> TBO Air is on
                    <span class="font-semibold">{{ $airEnvironment }}</span> while TBO Hotel is on
                    <span class="font-semibold">{{ $effectiveEnvironment }}</span>. Deliberate is fine; accidental
                    means half your bookings are not real.
                </div>
            @endif

            @can('supplier.tbohotel.manage')
                <form method="POST" action="{{ route('admin.tbo-hotel.settings.update') }}" class="mt-5">
                    @csrf
                    @method('PUT')

                    <x-input-label for="environment" value="Global environment" />
                    <div class="mt-1 flex gap-2">
                        <select id="environment" name="environment"
                                class="block w-full rounded-lg border-gray-300 py-2 pl-3.5 pr-8 text-sm text-gray-700 shadow-sm focus:border-brand-500 focus:ring-brand-500">
                            <option value="test" @selected($globalEnvironment === 'test')>Test (staging)</option>
                            <option value="live" @selected($globalEnvironment === 'live')>Live (production)</option>
                        </select>
                        <x-primary-button type="submit" class="shrink-0">Save</x-primary-button>
                    </div>
                    <x-input-error :messages="$errors->get('environment')" class="mt-2" />
                </form>
            @else
                <p class="mt-5 text-sm text-gray-500">
                    The platform default is <span class="font-semibold text-brand-900">{{ $globalEnvironment }}</span>.
                    Changing it needs the TBO Hotel <span class="font-mono text-xs">manage</span> permission.
                </p>
            @endcan

            {{-- Precedence, spelled out. "Effective" is otherwise a number from nowhere. --}}
            <dl class="mt-6 space-y-2 border-t border-gray-100 pt-4 text-sm">
                <div class="flex items-baseline justify-between gap-4">
                    <dt class="text-gray-500">Your override</dt>
                    <dd class="font-medium text-brand-900">
                        {{ $userOverride ?: 'none — following the global default' }}
                    </dd>
                </div>
                <div class="flex items-baseline justify-between gap-4">
                    <dt class="text-gray-500">Global setting</dt>
                    <dd class="font-medium text-brand-900">{{ $globalEnvironment }}</dd>
                </div>
                <div class="flex items-baseline justify-between gap-4">
                    <dt class="text-gray-500">Config default <span class="text-gray-400">(TBOHOTEL_ENV)</span></dt>
                    <dd class="font-medium text-brand-900">{{ $configDefault }}</dd>
                </div>
            </dl>

            <p class="mt-4 text-xs text-gray-500">
                A user's own override wins over the global setting, and it is one switch across every
                supplier — an agent who is testing is testing everything. Reaching live still needs
                the <span class="font-mono">supplier.tbohotel.live</span> permission; without it an
                override to live quietly falls back to test rather than spending real money. Overrides
                are set per user under <a href="{{ route('admin.users.index') }}" class="font-medium text-brand-700 underline">Users</a>.
            </p>
        </div>

        <div class="space-y-6">
            {{-- Connection --}}
            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <h2 class="text-sm font-semibold text-brand-900">Connection</h2>
                <p class="mt-1 text-sm text-gray-500">
                    Answers "will live work if I switch to it" without anyone reading a password.
                </p>

                <div class="mt-4 space-y-4">
                    @foreach ($environments as $env => $card)
                        <div class="rounded-lg border border-gray-200 p-3 {{ $env === $effectiveEnvironment ? 'bg-gray-50' : '' }}">
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $env }}</span>
                                @if ($card['configured'])
                                    <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-600/20">
                                        Credentials set
                                    </span>
                                @else
                                    <span class="rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700 ring-1 ring-inset ring-red-600/20">
                                        Not configured
                                    </span>
                                @endif
                            </div>
                            <p class="mt-2 break-all font-mono text-xs text-gray-600">{{ $card['base_url'] ?: '— no base URL —' }}</p>
                            <p class="mt-1 font-mono text-xs text-gray-400">{{ $card['username'] ?: '— no username —' }}</p>
                        </div>
                    @endforeach
                </div>

                <p class="mt-4 text-xs text-gray-500">
                    Run <span class="font-mono">php artisan tbohotel:ping</span> to prove the credentials
                    actually answer. Unlike TBO Air, the hotel API is not IP-restricted.
                </p>
            </div>

            {{-- Search cache --}}
            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <h2 class="text-sm font-semibold text-brand-900">Search cache</h2>
                <p class="mt-1 text-sm text-gray-500">
                    Results are held for {{ $cacheTtl }}s per user, well inside the 30 minutes a
                    BookingCode stays valid — a cached price that outlived its code would offer a room
                    nobody can book.
                </p>

                <p class="mt-3 text-xs text-gray-500">
                    Entries are keyed by environment, so switching above cannot serve a test price as a
                    live one. Clearing is for stale prices, not for the switch.
                </p>

                @can('supplier.tbohotel.manage')
                    <form method="POST" action="{{ route('admin.tbo-hotel.cache.flush') }}" class="mt-4">
                        @csrf
                        <x-secondary-button type="submit">Clear cached searches</x-secondary-button>
                    </form>
                @endcan

                <p class="mt-3 text-xs text-gray-400">Generation {{ $cacheGeneration }}</p>
            </div>
        </div>
    </div>
</x-app-layout>
