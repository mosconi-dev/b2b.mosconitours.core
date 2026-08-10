<x-app-layout>
    <x-slot name="header">
        <x-page-heading title="Wallet" :subtitle="$agency?->name ?? 'Agency e-wallet'">
            <x-slot name="icon">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 12a2.25 2.25 0 00-2.25-2.25H15a3 3 0 11-6 0H5.25A2.25 2.25 0 003 12m18 0v6a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 18v-6m18 0V9M3 12V9m18 0a2.25 2.25 0 00-2.25-2.25H5.25A2.25 2.25 0 003 9m18 0V6a2.25 2.25 0 00-2.25-2.25H5.25A2.25 2.25 0 003 6v3" />
                </svg>
            </x-slot>
        </x-page-heading>
    </x-slot>

    @if ($wallet)
        <x-slot name="actions">
            @can('create', \App\Models\WalletLoadRequest::class)
                <a href="{{ route('wallet.requests.create') }}"
                   class="inline-flex shrink-0 items-center gap-2 rounded-lg bg-blue-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    Request Load
                </a>
            @endcan
            @can('wallet.load.view')
                <a href="{{ route('wallet.requests.index') }}"
                   class="inline-flex shrink-0 items-center gap-2 rounded-lg border border-gray-300 bg-white px-3.5 py-2 text-sm font-semibold text-gray-700 shadow-sm transition hover:bg-gray-50">
                    Load Requests
                </a>
            @endcan
        </x-slot>
    @endif

    <div class="space-y-6">
        <x-admin.flash />

        @if (! $wallet)
            <div class="rounded-xl border border-gray-200 bg-white p-12 text-center shadow-sm">
                <p class="text-sm font-medium text-brand-900">No wallet</p>
                <p class="mx-auto mt-1 max-w-md text-sm text-gray-500">
                    The wallet belongs to an agency, and your account is not tied to one.
                    Platform staff review other agencies' load requests instead.
                </p>
                @can('wallet.load.view')
                    <a href="{{ route('wallet.requests.index') }}"
                       class="mt-4 inline-flex items-center gap-2 rounded-lg bg-blue-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700">
                        Go to Load Requests
                    </a>
                @endcan
            </div>
        @else
            {{-- Balance --}}
            <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Available balance</p>
                <p class="mt-1 text-3xl font-semibold tracking-tight text-brand-900">
                    <span class="text-lg font-medium text-gray-400">{{ $wallet->currency }}</span>
                    {{ $wallet->formattedBalance() }}
                </p>
                <p class="mt-2 text-xs text-gray-500">
                    Shared by everyone in {{ $agency->name }} — the wallet belongs to the agency, not to a user.
                </p>
            </div>

            {{-- Ledger --}}
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-100 px-6 py-4">
                    <h2 class="text-base font-semibold text-brand-900">Ledger</h2>
                    <p class="mt-0.5 text-xs text-gray-500">Every movement, newest first. Entries are never edited or removed.</p>
                </div>

                @if ($entries->isEmpty())
                    <div class="p-12 text-center">
                        <p class="text-sm font-medium text-brand-900">Nothing yet</p>
                        <p class="mt-1 text-sm text-gray-500">Approved load requests will appear here.</p>
                    </div>
                @else
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
                                        <td class="whitespace-nowrap px-5 py-3.5 text-right text-gray-700">{{ number_format((float) $entry->balance_after, 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($entries->hasPages())
                        <div class="border-t border-gray-100 px-5 py-3">{{ $entries->links() }}</div>
                    @endif
                @endif
            </div>
        @endif
    </div>
</x-app-layout>
