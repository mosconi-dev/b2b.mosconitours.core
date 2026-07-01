<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="flex items-center gap-2.5 text-2xl font-bold tracking-tight text-brand-900">
                    <svg class="h-7 w-7 text-brand-700" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.875 1.875 0 010 3.75H5.625a1.875 1.875 0 010-3.75z" />
                    </svg>
                    API Logs
                </h1>
                <p class="mt-1 text-sm text-gray-500">TBO Air authentication &amp; search requests and responses.</p>
            </div>

            {{-- Type filter --}}
            <div class="inline-flex rounded-lg border border-gray-200 bg-white p-1 shadow-sm">
                @php
                    $tabs = ['' => 'All', 'authenticate' => 'Auth', 'search' => 'Search'];
                @endphp
                @foreach ($tabs as $value => $label)
                    <a href="{{ route('api-logs', array_filter(['type' => $value])) }}"
                       @class([
                           'rounded-md px-3.5 py-1.5 text-sm font-medium transition',
                           'bg-brand-800 text-white shadow-sm' => (string) $type === (string) $value,
                           'text-gray-500 hover:text-gray-700' => (string) $type !== (string) $value,
                       ])>
                        {{ $label }}
                    </a>
                @endforeach
            </div>
        </div>
    </x-slot>

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
        @if ($logs->isEmpty())
            <div class="p-12 text-center">
                <p class="text-sm font-medium text-brand-900">No API calls logged yet</p>
                <p class="mt-1 text-sm text-gray-500">Run a flight search and the request/response will appear here.</p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-100 text-sm">
                    <thead>
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                            <th class="px-5 py-3">When</th>
                            <th class="px-5 py-3">Type</th>
                            <th class="px-5 py-3">Status</th>
                            <th class="px-5 py-3">Time</th>
                            <th class="px-5 py-3">Summary</th>
                            <th class="px-5 py-3">User</th>
                            <th class="px-5 py-3 text-right">Detail</th>
                        </tr>
                    </thead>

                    @foreach ($logs as $log)
                        @php
                            $ok = $log->successful;
                            $statusClass = $ok
                                ? 'bg-emerald-50 text-emerald-700 ring-emerald-600/20'
                                : 'bg-red-50 text-red-700 ring-red-600/20';
                            $typeClass = $log->type === 'search'
                                ? 'bg-blue-50 text-blue-700 ring-blue-600/20'
                                : 'bg-indigo-50 text-indigo-700 ring-indigo-600/20';
                        @endphp
                        <tbody x-data="{
                                open: false,
                                loading: false,
                                loaded: false,
                                res: '',
                                toggle() {
                                    this.open = !this.open;
                                    if (this.open && !this.loaded) {
                                        this.loaded = true;
                                        this.loading = true;
                                        fetch('{{ route('api-logs.show', $log->id) }}', { headers: { Accept: 'application/json' } })
                                            .then((r) => r.json())
                                            .then((d) => { this.res = JSON.stringify(d.response, null, 2); })
                                            .catch(() => { this.res = 'Failed to load response.'; })
                                            .finally(() => { this.loading = false; });
                                    }
                                },
                            }" class="divide-y divide-gray-100 border-t border-gray-100">
                            <tr class="cursor-pointer transition hover:bg-gray-50" @click="toggle()">
                                <td class="whitespace-nowrap px-5 py-3 text-gray-500">{{ $log->created_at->format('M j, H:i:s') }}</td>
                                <td class="px-5 py-3">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium capitalize ring-1 ring-inset {{ $typeClass }}">{{ $log->type }}</span>
                                </td>
                                <td class="px-5 py-3">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $statusClass }}">
                                        {{ $log->status_code ?? 'ERR' }}
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-5 py-3 text-gray-600">{{ $log->duration_ms }} ms</td>
                                <td class="px-5 py-3 font-medium text-brand-900">{{ $log->summary() }}</td>
                                <td class="whitespace-nowrap px-5 py-3 text-gray-500">{{ $log->user?->name ?? '—' }}</td>
                                <td class="px-5 py-3 text-right">
                                    <svg class="ml-auto h-4 w-4 text-gray-400 transition-transform" :class="open && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                                    </svg>
                                </td>
                            </tr>
                            <tr x-show="open" x-cloak>
                                <td colspan="7" class="bg-gray-50/70 px-5 py-4">
                                    @if ($log->error)
                                        <p class="mb-3 rounded-lg bg-red-50 px-3 py-2 text-xs font-medium text-red-700">{{ $log->error }}</p>
                                    @endif
                                    <p class="mb-2 break-all text-xs text-gray-400">{{ $log->endpoint }}</p>
                                    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                                        <div>
                                            <p class="mb-1.5 text-xs font-semibold uppercase tracking-wide text-gray-400">Request</p>
                                            <pre class="max-h-80 overflow-auto rounded-lg bg-brand-950 p-3 text-[11px] leading-relaxed text-emerald-200">{{ json_encode($log->request, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                                        </div>
                                        <div>
                                            <p class="mb-1.5 text-xs font-semibold uppercase tracking-wide text-gray-400">Response</p>
                                            <p x-show="loading" class="rounded-lg bg-brand-950 p-3 text-[11px] text-gray-400">Loading response…</p>
                                            <pre x-show="!loading" class="max-h-80 overflow-auto rounded-lg bg-brand-950 p-3 text-[11px] leading-relaxed text-sky-200" x-text="res"></pre>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    @endforeach
                </table>
            </div>

            <div class="border-t border-gray-100 px-5 py-3">
                {{ $logs->links() }}
            </div>
        @endif
    </div>
</x-app-layout>
