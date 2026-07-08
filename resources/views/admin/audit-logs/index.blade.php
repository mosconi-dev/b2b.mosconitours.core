<x-app-layout>
    <x-slot name="header">
        <div>
            <h1 class="flex items-center gap-2.5 text-2xl font-bold tracking-tight text-brand-900">
                <svg class="h-7 w-7 text-brand-700" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25z" />
                </svg>
                Audit Logs
            </h1>
            <p class="mt-1 text-sm text-gray-500">Security and administrative events, most recent first.</p>
        </div>
    </x-slot>

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-100 text-sm">
                <thead>
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <th class="px-5 py-3">Event</th>
                        <th class="px-5 py-3">Target</th>
                        <th class="px-5 py-3">By</th>
                        <th class="px-5 py-3">IP</th>
                        <th class="px-5 py-3 text-right">When</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($logs as $log)
                        <tr class="transition hover:bg-gray-50">
                            <td class="px-5 py-3.5">
                                <span class="inline-flex items-center rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700 ring-1 ring-inset ring-indigo-600/20">
                                    {{ $log->label() }}
                                </span>
                            </td>
                            <td class="px-5 py-3.5 text-gray-600">{{ $log->target() ?? '—' }}</td>
                            <td class="px-5 py-3.5 text-gray-900">{{ $log->user?->name ?? 'System' }}</td>
                            <td class="px-5 py-3.5 font-mono text-xs text-gray-400">{{ $log->ip_address ?? '—' }}</td>
                            <td class="px-5 py-3.5 text-right text-gray-500" title="{{ $log->created_at }}">
                                {{ $log->created_at?->diffForHumans() }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-5 py-12 text-center">
                                <p class="text-sm font-medium text-brand-900">No audit entries yet</p>
                                <p class="mt-1 text-sm text-gray-500">Administrative actions will appear here.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($logs->hasPages())
            <div class="border-t border-gray-100 px-5 py-3">
                {{ $logs->links() }}
            </div>
        @endif
    </div>
</x-app-layout>
