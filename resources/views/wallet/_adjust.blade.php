{{-- Expects: $wallet. Only rendered for holders of wallet.adjust — this moves money
     with no request and no second pair of eyes. --}}
<div x-data="{ open: {{ $errors->hasAny(['direction', 'amount', 'reason']) ? 'true' : 'false' }} }"
     class="rounded-xl border border-amber-200 bg-amber-50/40 p-6 shadow-sm">
    <div class="flex items-start justify-between gap-3">
        <div>
            <h2 class="text-base font-semibold text-brand-900">Manual adjustment</h2>
            <p class="mt-1 text-sm text-gray-600">
                Posts a new ledger entry. Use this for corrections that are not tied to a single
                entry — to undo one specific entry, use <span class="font-medium">Reverse</span> on its row.
            </p>
        </div>
        <button type="button" x-on:click="open = ! open"
                class="shrink-0 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50">
            <span x-text="open ? 'Close' : 'Adjust'"></span>
        </button>
    </div>

    <form x-show="open" x-cloak method="POST" action="{{ route('wallet.adjust', $wallet) }}" class="mt-5 space-y-4">
        @csrf

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <x-input-label for="direction" value="Direction" />
                <select id="direction" name="direction" required
                        class="mt-1 block w-full rounded-lg border-gray-300 py-2 pl-3.5 pr-8 text-sm text-gray-700 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    <option value="credit" @selected(old('direction') === 'credit')>Credit — add funds</option>
                    <option value="debit" @selected(old('direction') === 'debit')>Debit — take funds back</option>
                </select>
                <x-input-error :messages="$errors->get('direction')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="adjust_amount" value="Amount" />
                <div class="relative mt-1">
                    <span class="pointer-events-none absolute inset-y-0 left-3.5 flex items-center text-sm text-gray-400">{{ $wallet->currency }}</span>
                    <x-text-input id="adjust_amount" name="amount" type="text" inputmode="decimal"
                                  class="block w-full pl-14" :value="old('amount')" required placeholder="0.00" />
                </div>
                <x-input-error :messages="$errors->get('amount')" class="mt-2" />
            </div>
        </div>

        <div>
            <x-input-label for="reason" value="Reason" />
            <x-text-input id="reason" name="reason" type="text" class="mt-1 block w-full"
                          :value="old('reason')" required maxlength="255"
                          placeholder="e.g. Duplicate load approved on 10 Aug" />
            <p class="mt-1 text-xs text-gray-500">Recorded on the ledger and in the audit trail, permanently.</p>
            <x-input-error :messages="$errors->get('reason')" class="mt-2" />
        </div>

        <p class="text-xs text-gray-500">
            A debit may take the balance below zero — a correction has to be recordable even when the
            funds have already been spent.
        </p>

        <div class="flex justify-end">
            <button type="submit"
                    class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700">
                Post Adjustment
            </button>
        </div>
    </form>
</div>
