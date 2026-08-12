<x-app-layout>
    <x-slot name="header">
        <x-page-heading title="Hotel Catalogue"
                        subtitle="TBO's Search takes hotel codes, so this is the inventory we can search — not a cache.">
            <x-slot name="icon">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008z" />
                </svg>
            </x-slot>
        </x-page-heading>
    </x-slot>

    <x-admin.flash />

    {{-- Totals --}}
    <div class="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-5">
        @php
            $tiles = [
                ['Countries', $totals['countries']],
                ['Cities', $totals['cities']],
                ['Carried', $totals['enabled']],
                ['Hotels', $totals['hotels']],
                ['Enriched', $totals['detailed']],
            ];
        @endphp
        @foreach ($tiles as [$label, $value])
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $label }}</p>
                <p class="mt-1 text-2xl font-semibold text-brand-900">{{ number_format($value) }}</p>
            </div>
        @endforeach
    </div>

    @can('supplier.tbohotel.sync')
        <div class="mb-6 rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
            <h2 class="text-sm font-semibold text-brand-900">Run a sync</h2>
            <p class="mt-1 text-sm text-gray-500">
                Each scope costs very differently: countries is one call, cities one per country,
                hotels one per city, and details one per fifty hotels. They run in the background.
            </p>

            <div class="mt-4 flex flex-wrap items-end gap-3">
                <form method="POST" action="{{ route('admin.hotel-catalogue.sync') }}">
                    @csrf
                    <input type="hidden" name="scope" value="countries">
                    <x-secondary-button type="submit">Sync countries</x-secondary-button>
                </form>

                <form method="POST" action="{{ route('admin.hotel-catalogue.sync') }}" class="flex items-end gap-2">
                    @csrf
                    <input type="hidden" name="scope" value="cities">
                    <div>
                        <x-input-label for="sync-country" value="Cities in" />
                        <select id="sync-country" name="target"
                                class="mt-1 rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                            @foreach ($countries as $country)
                                <option value="{{ $country->code }}" @selected($country->code === 'PH')>
                                    {{ $country->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <x-secondary-button type="submit">Sync cities</x-secondary-button>
                </form>

                <form method="POST" action="{{ route('admin.hotel-catalogue.sync') }}">
                    @csrf
                    <input type="hidden" name="scope" value="hotels">
                    <x-primary-button type="submit">Sync hotels in carried cities</x-primary-button>
                </form>

                <form method="POST" action="{{ route('admin.hotel-catalogue.sync') }}">
                    @csrf
                    <input type="hidden" name="scope" value="details">
                    <x-secondary-button type="submit">Enrich descriptions &amp; images</x-secondary-button>
                </form>
            </div>
        </div>
    @endcan

    {{-- Cities --}}
    <div class="mb-6 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
        <div class="flex flex-wrap items-end justify-between gap-3 border-b border-gray-100 px-5 py-4">
            <h2 class="text-sm font-semibold text-brand-900">Cities</h2>

            <form method="GET" class="flex flex-wrap items-end gap-2">
                <x-text-input name="q" value="{{ $filters['q'] }}" placeholder="Search cities…" class="text-sm" />
                <select name="country" class="rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                    <option value="">All countries</option>
                    @foreach ($countries as $country)
                        <option value="{{ $country->code }}" @selected($filters['country'] === $country->code)>
                            {{ $country->name }}
                        </option>
                    @endforeach
                </select>
                <select name="only" class="rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                    <option value="">All</option>
                    <option value="enabled" @selected($filters['only'] === 'enabled')>Carried only</option>
                </select>
                <x-secondary-button type="submit">Filter</x-secondary-button>
            </form>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-100 text-sm">
                <thead>
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <th class="px-5 py-3">City</th>
                        <th class="px-5 py-3">Code</th>
                        <th class="px-5 py-3">Country</th>
                        <th class="px-5 py-3 text-right">Hotels</th>
                        <th class="px-5 py-3">Last pulled</th>
                        <th class="px-5 py-3 text-right">Carried</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($cities as $city)
                        <tr class="transition hover:bg-gray-50">
                            <td class="px-5 py-3 font-medium text-brand-900">{{ $city->name }}</td>
                            <td class="px-5 py-3 font-mono text-xs text-gray-400">{{ $city->code }}</td>
                            <td class="px-5 py-3 text-gray-600">{{ $city->country_code }}</td>
                            <td class="px-5 py-3 text-right text-gray-600">{{ number_format($city->hotels_count) }}</td>
                            <td class="px-5 py-3 text-gray-500" title="{{ $city->hotels_synced_at }}">
                                {{ $city->hotels_synced_at?->diffForHumans() ?? '—' }}
                            </td>
                            <td class="px-5 py-3 text-right">
                                @can('supplier.tbohotel.sync')
                                    <form method="POST" action="{{ route('admin.hotel-catalogue.cities.toggle', $city) }}">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" @class([
                                            'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset transition',
                                            'bg-emerald-50 text-emerald-700 ring-emerald-600/20 hover:bg-emerald-100' => $city->is_enabled,
                                            'bg-gray-50 text-gray-500 ring-gray-500/20 hover:bg-gray-100' => ! $city->is_enabled,
                                        ])>
                                            {{ $city->is_enabled ? 'Carried' : 'Not carried' }}
                                        </button>
                                    </form>
                                @else
                                    <span class="text-xs text-gray-500">{{ $city->is_enabled ? 'Carried' : '—' }}</span>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-12 text-center">
                                <p class="text-sm font-medium text-brand-900">No cities yet</p>
                                <p class="mt-1 text-sm text-gray-500">Sync countries, then a country's cities, then choose which to carry.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($cities->hasPages())
            <div class="border-t border-gray-100 px-5 py-3">{{ $cities->links() }}</div>
        @endif
    </div>

    {{-- Recent runs --}}
    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
        <div class="border-b border-gray-100 px-5 py-4">
            <h2 class="text-sm font-semibold text-brand-900">Recent syncs</h2>
            <p class="mt-1 text-sm text-gray-500">
                A run that skipped cities says which, and re-running picks them up — everything is upserted.
            </p>
        </div>

        <table class="min-w-full divide-y divide-gray-100 text-sm">
            <thead>
                <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                    <th class="px-5 py-3">Scope</th>
                    <th class="px-5 py-3">Status</th>
                    <th class="px-5 py-3 text-right">Processed</th>
                    <th class="px-5 py-3">By</th>
                    <th class="px-5 py-3 text-right">When</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($runs as $run)
                    @php
                        $statusClass = match ($run->status) {
                            \App\Models\HotelSyncRun::COMPLETED => $run->failed > 0
                                ? 'bg-amber-50 text-amber-700 ring-amber-600/20'
                                : 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
                            \App\Models\HotelSyncRun::FAILED => 'bg-red-50 text-red-700 ring-red-600/20',
                            default => 'bg-sky-50 text-sky-700 ring-sky-600/20',
                        };
                        $label = $run->failed > 0 ? $run->status.' · '.$run->failed.' skipped' : $run->status;
                    @endphp
                    <tr class="align-top transition hover:bg-gray-50">
                        <td class="px-5 py-3">
                            <span class="font-medium text-brand-900">{{ $run->scope }}</span>
                            @if ($run->target)
                                <span class="ml-1 font-mono text-xs text-gray-400">{{ $run->target }}</span>
                            @endif
                            @if ($run->message)
                                <p class="mt-1 max-w-md text-xs text-red-600">{{ $run->message }}</p>
                            @endif
                            @if ($run->failed > 0)
                                <ul class="mt-1 space-y-0.5 text-xs text-amber-700">
                                    @foreach (array_slice($run->failures ?? [], 0, 5) as $failure)
                                        <li>{{ $failure['label'] ?? $failure['target'] }} — {{ $failure['reason'] }}</li>
                                    @endforeach
                                    @if ($run->failed > 5)
                                        <li class="text-gray-400">… and {{ $run->failed - 5 }} more</li>
                                    @endif
                                </ul>
                            @endif
                        </td>
                        <td class="px-5 py-3">
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $statusClass }}">
                                {{ $label }}
                            </span>
                        </td>
                        <td class="px-5 py-3 text-right text-gray-600">{{ number_format($run->processed) }}</td>
                        <td class="px-5 py-3 text-gray-500">{{ $run->user?->name ?? 'Console' }}</td>
                        <td class="px-5 py-3 text-right text-gray-500" title="{{ $run->started_at }}">
                            {{ $run->started_at?->diffForHumans() }}
                            @if ($run->durationSeconds() !== null)
                                <span class="text-gray-400">· {{ $run->durationSeconds() }}s</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-5 py-12 text-center text-sm text-gray-500">No syncs run yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-app-layout>
