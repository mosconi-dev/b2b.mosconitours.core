{{-- Rendered by App\View\Components\WalletBalance, which decides visibility. --}}
<a href="{{ route('wallet.index') }}"
   title="{{ $isNegative() ? 'Agency wallet is overdrawn' : 'Agency wallet balance' }}"
   @class([
       'inline-flex shrink-0 items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-sm font-semibold transition',
       'border-red-200 bg-red-50 text-red-700 hover:bg-red-100' => $isNegative(),
       'border-gray-200 bg-gray-50 text-brand-900 hover:bg-gray-100' => ! $isNegative(),
   ])>
    <svg class="h-4 w-4 {{ $isNegative() ? 'text-red-500' : 'text-gray-400' }}" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" d="M21 12a2.25 2.25 0 00-2.25-2.25H15a3 3 0 11-6 0H5.25A2.25 2.25 0 003 12m18 0v6a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 18v-6m18 0V9M3 12V9m18 0a2.25 2.25 0 00-2.25-2.25H5.25A2.25 2.25 0 003 9m18 0V6a2.25 2.25 0 00-2.25-2.25H5.25A2.25 2.25 0 003 6v3" />
    </svg>
    <span class="text-xs font-medium {{ $isNegative() ? 'text-red-400' : 'text-gray-400' }}">{{ $currency() }}</span>
    <span class="tabular-nums">{{ $formatted() }}</span>
</a>
