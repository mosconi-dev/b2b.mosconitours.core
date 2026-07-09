@php
    $showUser = $showUser ?? true;
    $cols = $showUser ? 7 : 6;
@endphp

<div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
    @if ($logs->isEmpty())
        <div class="p-12 text-center">
            <p class="text-sm font-medium text-brand-900">No API calls logged yet</p>
            <p class="mt-1 text-sm text-gray-500">TBO Air authentication &amp; search requests and responses will appear here.</p>
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
                        @if ($showUser)
                            <th class="px-5 py-3">User</th>
                        @endif
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
                                <div class="flex items-center gap-1.5">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium capitalize ring-1 ring-inset {{ $typeClass }}">{{ $log->type }}</span>
                                    @if ($log->environment)
                                        <span @class([
                                            'inline-flex items-center rounded-full px-1.5 py-0.5 text-[10px] font-semibold uppercase ring-1 ring-inset',
                                            'bg-red-50 text-red-700 ring-red-600/30' => $log->environment === 'live',
                                            'bg-gray-50 text-gray-500 ring-gray-500/20' => $log->environment !== 'live',
                                        ])>{{ $log->environment }}</span>
                                    @endif
                                </div>
                            </td>
                            <td class="px-5 py-3">
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $statusClass }}">
                                    {{ $log->status_code ?? 'ERR' }}
                                </span>
                            </td>
                            <td class="whitespace-nowrap px-5 py-3 text-gray-600">{{ $log->duration_ms }} ms</td>
                            <td class="px-5 py-3 font-medium text-brand-900">{{ $log->summary() }}</td>
                            @if ($showUser)
                                <td class="whitespace-nowrap px-5 py-3 text-gray-500">{{ $log->user?->name ?? '—' }}</td>
                            @endif
                            <td class="px-5 py-3 text-right">
                                <svg class="ml-auto h-4 w-4 text-gray-400 transition-transform" :class="open && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                                </svg>
                            </td>
                        </tr>
                        <tr x-show="open" x-cloak>
                            <td colspan="{{ $cols }}" class="bg-gray-50/70 px-5 py-4">
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
