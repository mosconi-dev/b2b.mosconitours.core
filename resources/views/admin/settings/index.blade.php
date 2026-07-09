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

                <form method="POST" action="{{ route('admin.settings.tbo.update') }}" class="mt-5">
                    @csrf
                    @method('PUT')

                    <x-input-label for="environment" value="Global environment" />
                    <div class="mt-1 flex gap-2">
                        <select id="environment" name="environment"
                                class="block w-full rounded-lg border-gray-300 py-2 pl-3.5 pr-8 text-sm text-gray-700 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="test" @selected($globalEnvironment === 'test')>Test (staging)</option>
                            <option value="live" @selected($globalEnvironment === 'live')>Live (production)</option>
                        </select>
                        <button type="submit" class="shrink-0 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700">
                            Save
                        </button>
                    </div>
                    <p class="mt-1 text-xs text-gray-500">The platform default. A per-user override can take precedence.</p>
                    <x-input-error :messages="$errors->get('environment')" class="mt-2" />
                </form>

                <div class="mt-6 border-t border-gray-100 pt-5">
                    <h3 class="text-sm font-semibold text-brand-900">Environments</h3>
                    <p class="mt-1 text-xs text-gray-500">
                        Per environment: the cached TokenId and how long it's cached — the two go together. Paste a TokenId
                        <strong>with</strong> a TTL (max 86400s) to reuse a session, or <strong>clear both and Save</strong> to
                        force a fresh login on the next call. (Flush clears the token in one click.)
                    </p>
                    <div class="mt-3 space-y-3">
                        @foreach (['test', 'live'] as $env)
                            @php($bag = $errors->getBag('tbo_'.$env))
                            <div class="rounded-lg border border-gray-200 p-4">
                                <div class="flex items-center justify-between">
                                    <span class="text-xs font-semibold uppercase tracking-wide {{ $env === 'live' ? 'text-red-600' : 'text-gray-500' }}">{{ $env }}</span>
                                    <form method="POST" action="{{ route('admin.settings.tbo.flush', $env) }}">
                                        @csrf
                                        <button type="submit" class="text-xs font-medium text-red-600 transition hover:text-red-700">Flush</button>
                                    </form>
                                </div>

                                <form method="POST" action="{{ route('admin.settings.tbo.env', $env) }}" class="mt-3 space-y-3">
                                    @csrf
                                    @method('PUT')

                                    <div>
                                        <x-input-label :for="$env.'_ttl'" value="Token TTL (seconds)" />
                                        <x-text-input :id="$env.'_ttl'" name="ttl" type="number" max="86400"
                                                      class="mt-1 block w-full" :value="old('ttl', $environments[$env]['ttl'])" />
                                        <x-input-error :messages="$bag->get('ttl')" class="mt-2" />
                                    </div>

                                    <div>
                                        <x-input-label :for="$env.'_token'" value="Cached TokenId" />
                                        <div class="mt-1 flex gap-2">
                                            <input type="text" :id="$env.'_token'" name="token" value="{{ $environments[$env]['token'] }}"
                                                   placeholder="{{ $environments[$env]['token'] ? '' : 'No cached token — paste one to reuse…' }}"
                                                   class="block w-full rounded-lg border-gray-300 py-1.5 font-mono text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                                            <button type="submit" class="shrink-0 rounded-lg bg-blue-600 px-4 py-1.5 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700">
                                                Save
                                            </button>
                                        </div>
                                        <x-input-error :messages="$bag->get('token')" class="mt-2" />
                                    </div>
                                </form>
                            </div>
                        @endforeach
                    </div>
                    <p class="mt-2 text-xs text-gray-400">
                        A shorter TTL applies from the next authentication. A pasted token must still be valid and your
                        requests must exit the TBO-whitelisted IP.
                    </p>
                </div>
            @else
                <dl class="mt-4 text-sm">
                    <dt class="text-gray-500">Global environment</dt>
                    <dd class="font-medium text-brand-900">{{ $globalEnvironment }}</dd>
                </dl>
                <p class="mt-4 text-xs text-gray-400">You need the “Supplier · TBO · Use Live / Manage” permission to change these.</p>
            @endcan
        </div>
    </div>
</x-app-layout>
