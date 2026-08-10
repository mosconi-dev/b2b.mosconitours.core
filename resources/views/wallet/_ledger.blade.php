{{-- Expects: $entries (paginator). Shared by /wallet and the agency Wallet tab.
     Corrections are posted as new opposing entries, so both the mistake and its
     reversal stay visible — nothing here ever edits or hides a row. --}}
<div class="overflow-x-auto">
    <table class="min-w-full divide-y divide-gray-100 text-sm">
        <thead>
            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <th class="px-5 py-3">Date</th>
                <th class="px-5 py-3">Description</th>
                <th class="px-5 py-3">By</th>
                <th class="px-5 py-3 text-right">Amount</th>
                <th class="px-5 py-3 text-right">Balance</th>
                <th class="px-5 py-3 text-right">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @foreach ($entries as $entry)
                <tr x-data="{ reversing: false }" class="align-top transition hover:bg-gray-50">
                    <td class="whitespace-nowrap px-5 py-3.5 text-gray-500">{{ $entry->created_at?->format('d M Y H:i') }}</td>
                    <td class="px-5 py-3.5 text-gray-700">
                        {{ $entry->description ?? '—' }}
                        @if ($entry->isReversal())
                            <span class="ml-1 inline-flex items-center rounded-full bg-violet-50 px-2 py-0.5 text-xs font-medium text-violet-700 ring-1 ring-inset ring-violet-600/20">Correction</span>
                        @endif
                        @if ($entry->isReversed())
                            <span class="ml-1 inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-500/20">Reversed</span>
                        @endif

                        {{-- Reason capture: a reversal is permanent, so it is never a bare confirm(). --}}
                        <div x-show="reversing" x-cloak class="mt-2">
                            <form method="POST" action="{{ route('wallet.reverse', $entry) }}" class="flex flex-wrap items-center gap-2">
                                @csrf
                                @method('PATCH')
                                <input type="text" name="reason" required maxlength="255"
                                       placeholder="Why is this being reversed?"
                                       class="w-72 rounded-lg border-gray-300 py-1.5 text-xs text-gray-700 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <button type="submit"
                                        class="rounded-md border border-red-200 bg-white px-2.5 py-1 text-xs font-medium text-red-600 shadow-sm transition hover:bg-red-50">
                                    Confirm reversal
                                </button>
                                <button type="button" x-on:click="reversing = false"
                                        class="text-xs font-medium text-gray-500 hover:text-gray-700">Cancel</button>
                            </form>
                        </div>
                    </td>
                    <td class="px-5 py-3.5 text-gray-500">{{ $entry->user?->name ?? '—' }}</td>
                    <td class="whitespace-nowrap px-5 py-3.5 text-right font-medium {{ $entry->isCredit() ? 'text-emerald-700' : 'text-red-700' }}">
                        {{ $entry->signedAmount() }}
                    </td>
                    <td class="whitespace-nowrap px-5 py-3.5 text-right {{ bccomp((string) $entry->balance_after, '0', 2) < 0 ? 'font-semibold text-red-700' : 'text-gray-700' }}">
                        {{ number_format((float) $entry->balance_after, 2) }}
                    </td>
                    <td class="px-5 py-3.5 text-right">
                        @can('reverse', $entry)
                            <button type="button" x-show="! reversing" x-on:click="reversing = true"
                                    class="rounded-md border border-gray-300 bg-white px-2.5 py-1 text-xs font-medium text-gray-700 shadow-sm transition hover:bg-gray-50">
                                Reverse
                            </button>
                        @else
                            <span class="text-xs text-gray-400">—</span>
                        @endcan
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

@if ($entries->hasPages())
    <div class="border-t border-gray-100 px-5 py-3">{{ $entries->links() }}</div>
@endif
