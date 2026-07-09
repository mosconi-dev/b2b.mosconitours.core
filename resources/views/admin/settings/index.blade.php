<x-app-layout>
    <x-slot name="header">
        <div>
            <h1 class="flex items-center gap-2.5 text-2xl font-bold tracking-tight text-brand-900">
                <svg class="h-7 w-7 text-brand-700" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.281z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
                Settings
            </h1>
            <p class="mt-1 text-sm text-gray-500">Platform configuration.</p>
        </div>
    </x-slot>

    <div class="max-w-2xl space-y-6">
        <x-admin.flash />

        <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            <div class="flex items-center justify-between">
                <h2 class="text-base font-semibold text-brand-900">TBO Air Environment</h2>
                @if ($effectiveEnvironment === 'live')
                    <span class="inline-flex items-center gap-1 rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-bold uppercase tracking-wide text-red-700 ring-1 ring-inset ring-red-600/30">
                        <span class="h-1.5 w-1.5 rounded-full bg-red-500"></span> Live
                    </span>
                @else
                    <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold uppercase tracking-wide text-emerald-700 ring-1 ring-inset ring-emerald-600/20">
                        Test
                    </span>
                @endif
            </div>
            <p class="mt-1 text-sm text-gray-500">
                Effective environment for your account is <span class="font-semibold text-brand-900">{{ $effectiveEnvironment }}</span>.
                Live runs real searches and bookings.
            </p>

            @can('supplier.tbo.manage')
                @if ($effectiveEnvironment === 'live')
                    <div class="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                        <strong>Live mode is active.</strong> Requests hit production TBO and can create real, billable bookings.
                    </div>
                @endif

                <form method="POST" action="{{ route('admin.settings.tbo.update') }}" class="mt-5 space-y-5">
                    @csrf
                    @method('PUT')

                    <div>
                        <x-input-label for="environment" value="Global environment" />
                        <select id="environment" name="environment"
                                class="mt-1 block w-full rounded-lg border-gray-300 py-2 pl-3.5 pr-8 text-sm text-gray-700 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="test" @selected($globalEnvironment === 'test')>Test (staging)</option>
                            <option value="live" @selected($globalEnvironment === 'live')>Live (production)</option>
                        </select>
                        <p class="mt-1 text-xs text-gray-500">The platform default. A per-user override can take precedence.</p>
                        <x-input-error :messages="$errors->get('environment')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="cache_key" value="Token cache key (base)" />
                        <x-text-input id="cache_key" name="cache_key" type="text" class="mt-1 block w-full font-mono"
                                      :value="old('cache_key', $cacheKey)" required />
                        <p class="mt-1 text-xs text-gray-500">
                            Effective key is <code class="rounded bg-gray-100 px-1">{{ $cacheKey }}:{env}</code>.
                            Changing it invalidates cached tokens (they re-authenticate on next call).
                        </p>
                        <x-input-error :messages="$errors->get('cache_key')" class="mt-2" />
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <x-input-label for="ttl_test" value="Test token TTL (seconds)" />
                            <x-text-input id="ttl_test" name="ttl_test" type="number" min="60" max="86400"
                                          class="mt-1 block w-full" :value="old('ttl_test', $ttlTest)" required />
                            <x-input-error :messages="$errors->get('ttl_test')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="ttl_live" value="Live token TTL (seconds)" />
                            <x-text-input id="ttl_live" name="ttl_live" type="number" min="60" max="86400"
                                          class="mt-1 block w-full" :value="old('ttl_live', $ttlLive)" required />
                            <x-input-error :messages="$errors->get('ttl_live')" class="mt-2" />
                        </div>
                    </div>
                    <p class="text-xs text-gray-500">
                        How long an authenticated token is cached before re-authenticating (60s–86400s; token validity is ~24h).
                        Keep a short live TTL so a quick live test expires on its own. Saving flushes any token whose TTL changed.
                    </p>

                    <div class="flex justify-end border-t border-gray-100 pt-5">
                        <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700">
                            Save Settings
                        </button>
                    </div>
                </form>

                <div class="mt-6 border-t border-gray-100 pt-5">
                    <h3 class="text-sm font-semibold text-brand-900">Cached tokens</h3>
                    <p class="mt-1 text-xs text-gray-500">Force a re-authentication by flushing the cached TBO token for an environment.</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach (['test', 'live'] as $env)
                            <form method="POST" action="{{ route('admin.settings.tbo.flush', $env) }}">
                                @csrf
                                <button type="submit" class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3.5 py-2 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50">
                                    Flush {{ $env }} token
                                </button>
                            </form>
                        @endforeach
                    </div>
                </div>
            @else
                <dl class="mt-4 grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-gray-500">Global environment</dt>
                        <dd class="font-medium text-brand-900">{{ $globalEnvironment }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Token cache key</dt>
                        <dd class="font-mono text-brand-900">{{ $cacheKey }}:{env}</dd>
                    </div>
                </dl>
                <p class="mt-4 text-xs text-gray-400">You need the “Supplier · TBO · Use Live / Manage” permission to change these.</p>
            @endcan
        </div>
    </div>
</x-app-layout>
