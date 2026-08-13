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

    @include('admin.tbo-hotel._tabs')

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

    {{-- Cities.

         Selection lives in Alpine rather than in a form wrapping the table: each row
         already carries its own toggle form, and a form inside a form is not markup a
         browser will honour. --}}
    <div class="mb-6 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm"
         x-data="{
             selected: [],
             pageIds: @js($cities->pluck('id')->all()),
             confirmingAll: false,
             get allOnPage() { return this.pageIds.length > 0 && this.pageIds.every(id => this.selected.includes(id)); },
             togglePage() {
                 this.selected = this.allOnPage ? [] : [...this.pageIds];
             },
         }">
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

        @can('supplier.tbohotel.sync')
            @php
                // Carried on every bulk action so "all matching" resolves the same set
                // the page was drawn from.
                $scope = ['q' => $filters['q'], 'country' => $filters['country'], 'only' => $filters['only']];
            @endphp

            <div class="border-b border-gray-100 bg-gray-50/70 px-5 py-3">
                {{-- Ticked rows. --}}
                <div x-show="selected.length" x-cloak class="flex flex-wrap items-center gap-3">
                    <span class="text-sm text-gray-600">
                        <span class="font-semibold text-brand-900" x-text="selected.length"></span> selected
                    </span>

                    @foreach ([1 => 'Carry', 0 => 'Stop carrying'] as $carry => $label)
                        <form method="POST" action="{{ route('admin.hotel-catalogue.cities.carry') }}">
                            @csrf
                            <input type="hidden" name="carry" value="{{ $carry }}">
                            @foreach ($scope as $key => $value)
                                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                            @endforeach
                            <template x-for="id in selected" :key="id">
                                <input type="hidden" name="cities[]" :value="id">
                            </template>
                            <button type="submit" @class([
                                'rounded-lg px-3 py-1.5 text-xs font-semibold shadow-sm transition',
                                'bg-emerald-600 text-white hover:bg-emerald-700' => $carry === 1,
                                'border border-gray-300 bg-white text-gray-700 hover:bg-gray-50' => $carry === 0,
                            ])>{{ $label }} selected</button>
                        </form>
                    @endforeach

                    <button type="button" @click="selected = []"
                            class="text-xs font-medium text-gray-500 hover:text-gray-700">Clear</button>
                </div>

                {{-- Everything the filter matches, which is the point: one country is
                     eight pages, and carrying a dozen cities a click at a time is how a
                     catalogue stays at two. --}}
                <div x-show="! selected.length" class="flex flex-wrap items-center gap-3">
                    <span class="text-sm text-gray-500">
                        Tick cities to carry them, or take the whole filter:
                    </span>

                    <template x-if="! confirmingAll">
                        <button type="button" @click="confirmingAll = true"
                                class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 shadow-sm transition hover:bg-gray-50">
                            Carry all {{ number_format($matching) }} matching
                        </button>
                    </template>

                    <template x-if="confirmingAll">
                        <span class="flex flex-wrap items-center gap-3">
                            <span class="text-sm font-medium text-brand-900">
                                Carry {{ number_format($matching) }}
                                {{ \Illuminate\Support\Str::plural('city', $matching) }}?
                                <span class="text-gray-500">Searching stays per-city, but every one needs its
                                properties pulled and enriched.</span>
                            </span>
                            <form method="POST" action="{{ route('admin.hotel-catalogue.cities.carry') }}">
                                @csrf
                                <input type="hidden" name="carry" value="1">
                                <input type="hidden" name="all" value="1">
                                @foreach ($scope as $key => $value)
                                    <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                                @endforeach
                                <button type="submit"
                                        class="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-emerald-700">
                                    Yes, carry them
                                </button>
                            </form>
                            <button type="button" @click="confirmingAll = false"
                                    class="text-xs font-medium text-gray-500 hover:text-gray-700">Cancel</button>
                        </span>
                    </template>
                </div>
            </div>
        @endcan

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-100 text-sm">
                <thead>
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                        @can('supplier.tbohotel.sync')
                            <th class="w-10 py-3 pl-5 pr-0">
                                <input type="checkbox" :checked="allOnPage" @change="togglePage()"
                                       title="Select every city on this page"
                                       class="rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                            </th>
                        @endcan
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
                        <tr class="transition hover:bg-gray-50" :class="selected.includes({{ $city->id }}) && 'bg-brand-50/50'">
                            @can('supplier.tbohotel.sync')
                                <td class="w-10 py-3 pl-5 pr-0">
                                    <input type="checkbox" value="{{ $city->id }}" x-model.number="selected"
                                           aria-label="Select {{ $city->name }}"
                                           class="rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                                </td>
                            @endcan
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
                            <td colspan="7" class="px-5 py-12 text-center">
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
