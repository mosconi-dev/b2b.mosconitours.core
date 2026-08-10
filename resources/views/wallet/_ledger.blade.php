{{-- Expects: $entries (paginator). Shared by /wallet and the agency Wallet tab.
     Append-only: corrections appear as their own entries, so nothing here edits
     or hides a row. --}}
<div class="overflow-x-auto">
    <table class="min-w-full divide-y divide-gray-100 text-sm">
        <thead>
            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <th class="px-5 py-3">Date</th>
                <th class="px-5 py-3">Description</th>
                <th class="px-5 py-3">By</th>
                <th class="px-5 py-3 text-right">Amount</th>
                <th class="px-5 py-3 text-right">Balance</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @foreach ($entries as $entry)
                <tr class="transition hover:bg-gray-50">
                    <td class="whitespace-nowrap px-5 py-3.5 text-gray-500">{{ $entry->created_at?->format('d M Y H:i') }}</td>
                    <td class="px-5 py-3.5 text-gray-700">{{ $entry->description ?? '—' }}</td>
                    <td class="px-5 py-3.5 text-gray-500">{{ $entry->user?->name ?? '—' }}</td>
                    <td class="whitespace-nowrap px-5 py-3.5 text-right font-medium {{ $entry->isCredit() ? 'text-emerald-700' : 'text-red-700' }}">
                        {{ $entry->signedAmount() }}
                    </td>
                    <td class="whitespace-nowrap px-5 py-3.5 text-right {{ bccomp((string) $entry->balance_after, '0', 2) < 0 ? 'font-semibold text-red-700' : 'text-gray-700' }}">
                        {{ number_format((float) $entry->balance_after, 2) }}
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

@if ($entries->hasPages())
    <div class="border-t border-gray-100 px-5 py-3">{{ $entries->links() }}</div>
@endif
