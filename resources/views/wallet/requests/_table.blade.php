{{-- Expects: $loadRequests (paginator). Optional: $showAgency (bool, default true).
     Shared by /wallet/requests and the agency Load Requests tab — the tab drops the
     Agency column, since every row on it belongs to the agency you are already in. --}}
@php
    $showAgency = $showAgency ?? true;
@endphp
<div class="overflow-x-auto">
    <table class="min-w-full divide-y divide-gray-100 text-sm">
        <thead>
            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <th class="px-5 py-3">Request</th>
                @if ($showAgency)
                    <th class="px-5 py-3">Agency</th>
                @endif
                <th class="px-5 py-3">Requested by</th>
                <th class="px-5 py-3 text-right">Amount</th>
                <th class="px-5 py-3">Status</th>
                <th class="px-5 py-3 text-right">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($loadRequests as $loadRequest)
                <tr class="align-top transition hover:bg-gray-50">
                    <td class="px-5 py-3.5">
                        <p class="font-mono text-xs text-gray-700">{{ $loadRequest->reference }}</p>
                        <p class="mt-0.5 text-xs text-gray-400">{{ $loadRequest->created_at?->format('d M Y H:i') }}</p>
                        @if ($loadRequest->payment_reference)
                            <p class="mt-1 text-xs text-gray-500">Ref: {{ $loadRequest->payment_reference }}</p>
                        @endif
                        @if ($loadRequest->remarks)
                            <p class="mt-1 max-w-xs text-xs text-gray-500">{{ $loadRequest->remarks }}</p>
                        @endif
                    </td>
                    @if ($showAgency)
                        <td class="px-5 py-3.5 text-gray-700">{{ $loadRequest->agency?->name ?? '—' }}</td>
                    @endif
                    <td class="px-5 py-3.5 text-gray-700">
                        {{ $loadRequest->requester?->name ?? '—' }}
                        @if ($loadRequest->reviewer)
                            <p class="mt-0.5 text-xs text-gray-400">
                                {{ $loadRequest->status->label() }} by {{ $loadRequest->reviewer->name }}
                            </p>
                        @endif
                        @if ($loadRequest->review_remarks)
                            <p class="mt-1 max-w-xs text-xs text-gray-500">{{ $loadRequest->review_remarks }}</p>
                        @endif
                    </td>
                    <td class="whitespace-nowrap px-5 py-3.5 text-right font-medium text-brand-900">
                        {{ $loadRequest->currency }} {{ $loadRequest->formattedAmount() }}
                    </td>
                    <td class="px-5 py-3.5">
                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $loadRequest->status->badgeClasses() }}">
                            {{ $loadRequest->status->label() }}
                        </span>
                    </td>
                    <td class="px-5 py-3.5">
                        <div class="flex items-center justify-end gap-2">
                            @can('review', $loadRequest)
                                <form method="POST" action="{{ route('wallet.requests.approve', $loadRequest) }}"
                                      onsubmit="return confirm('Approve {{ $loadRequest->reference }}? This credits {{ $loadRequest->formattedAmount() }} to {{ $loadRequest->agency?->name }}.');">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit"
                                            class="rounded-md border border-emerald-200 bg-white px-2.5 py-1 text-xs font-medium text-emerald-700 shadow-sm transition hover:bg-emerald-50">
                                        Approve
                                    </button>
                                </form>
                                <form method="POST" action="{{ route('wallet.requests.reject', $loadRequest) }}"
                                      onsubmit="return confirm('Reject {{ $loadRequest->reference }}?');">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit"
                                            class="rounded-md border border-gray-300 bg-white px-2.5 py-1 text-xs font-medium text-red-600 shadow-sm transition hover:bg-red-50">
                                        Reject
                                    </button>
                                </form>
                            @elsecan('cancel', $loadRequest)
                                <form method="POST" action="{{ route('wallet.requests.cancel', $loadRequest) }}"
                                      onsubmit="return confirm('Cancel {{ $loadRequest->reference }}?');">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit"
                                            class="rounded-md border border-gray-300 bg-white px-2.5 py-1 text-xs font-medium text-gray-700 shadow-sm transition hover:bg-gray-50">
                                        Cancel
                                    </button>
                                </form>
                            @else
                                <span class="text-xs text-gray-400">—</span>
                            @endcan
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $showAgency ? 6 : 5 }}" class="px-5 py-12 text-center">
                        <p class="text-sm font-medium text-brand-900">No load requests</p>
                        <p class="mt-1 text-sm text-gray-500">Top-up requests will appear here for review.</p>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

@if ($loadRequests->hasPages())
    <div class="border-t border-gray-100 px-5 py-3">{{ $loadRequests->links() }}</div>
@endif
