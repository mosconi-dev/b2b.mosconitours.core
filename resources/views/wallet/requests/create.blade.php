<x-app-layout>
    <x-slot name="header">
        <x-page-heading title="Request Load" :subtitle="$agency->name" />
    </x-slot>

    <x-slot name="back">
        <a href="{{ route('wallet.requests.index') }}" class="text-sm font-medium text-gray-500 hover:text-gray-700">&larr; Back to load requests</a>
    </x-slot>

    <div class="max-w-2xl">
        <x-admin.flash />

        <form method="POST" action="{{ route('wallet.requests.store') }}"
              class="space-y-6 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf

            <p class="rounded-lg bg-gray-50 px-3.5 py-2 text-sm text-gray-600">
                Loading <span class="font-semibold text-brand-900">{{ $agency->name }}</span>'s wallet.
                Current balance {{ $wallet->currency }} {{ $wallet->formattedBalance() }}.
            </p>

            <div>
                <x-input-label for="amount" value="Amount" />
                <div class="relative mt-1">
                    <span class="pointer-events-none absolute inset-y-0 left-3.5 flex items-center text-sm text-gray-400">{{ $wallet->currency }}</span>
                    <x-text-input id="amount" name="amount" type="text" inputmode="decimal"
                                  class="block w-full pl-14" :value="old('amount')" required autofocus
                                  placeholder="0.00" />
                </div>
                <x-input-error :messages="$errors->get('amount')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="payment_reference" value="Payment reference" />
                <x-text-input id="payment_reference" name="payment_reference" type="text" class="mt-1 block w-full"
                              :value="old('payment_reference')" placeholder="Deposit slip / transfer reference" />
                <p class="mt-1 text-xs text-gray-500">Helps whoever reviews this match it against the payment received.</p>
                <x-input-error :messages="$errors->get('payment_reference')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="remarks" value="Remarks" />
                <textarea id="remarks" name="remarks" rows="3"
                          class="mt-1 block w-full rounded-lg border-gray-300 text-sm text-gray-700 shadow-sm focus:border-blue-500 focus:ring-blue-500">{{ old('remarks') }}</textarea>
                <x-input-error :messages="$errors->get('remarks')" class="mt-2" />
            </div>

            <p class="text-xs text-gray-500">
                Nothing is credited until someone with approval rights reviews this — and it cannot be you.
            </p>

            <div class="flex items-center justify-end gap-3 border-t border-gray-100 pt-5">
                <a href="{{ route('wallet.requests.index') }}" class="text-sm font-medium text-gray-600 hover:text-gray-800">Cancel</a>
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700">
                    Submit Request
                </button>
            </div>
        </form>
    </div>
</x-app-layout>
