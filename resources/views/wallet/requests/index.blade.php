<x-app-layout>
    <x-slot name="header">
        <x-page-heading title="Load Requests" subtitle="Wallet top-ups awaiting review, and everything already decided.">
            <x-slot name="icon">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 12a2.25 2.25 0 00-2.25-2.25H15a3 3 0 11-6 0H5.25A2.25 2.25 0 003 12m18 0v6a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 18v-6m18 0V9M3 12V9m18 0a2.25 2.25 0 00-2.25-2.25H5.25A2.25 2.25 0 003 9m18 0V6a2.25 2.25 0 00-2.25-2.25H5.25A2.25 2.25 0 003 6v3" />
                </svg>
            </x-slot>
        </x-page-heading>
    </x-slot>

    <x-slot name="actions">
        {{-- Status filter --}}
        <div class="inline-flex rounded-lg border border-gray-200 bg-white p-1 shadow-sm">
            <a href="{{ route('wallet.requests.index') }}"
               @class([
                   'rounded-md px-3 py-1.5 text-sm font-medium transition',
                   'bg-gray-100 text-brand-900' => ! $status,
                   'text-gray-500 hover:text-gray-700' => (bool) $status,
               ])>All</a>
            @foreach ($statuses as $case)
                <a href="{{ route('wallet.requests.index', ['status' => $case->value]) }}"
                   @class([
                       'inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium transition',
                       'bg-gray-100 text-brand-900' => $status === $case->value,
                       'text-gray-500 hover:text-gray-700' => $status !== $case->value,
                   ])>
                    {{ $case->label() }}
                    {{-- Only Pending carries a count: it is the queue that needs
                         action. The others are archives, where an ever-growing
                         number would be noise rather than a prompt. --}}
                    @if ($case === \App\Enums\LoadRequestStatus::Pending && $pendingCount > 0)
                        <span class="inline-flex min-w-[1.25rem] items-center justify-center rounded-full bg-amber-100 px-1.5 py-0.5 text-[11px] font-semibold leading-none text-amber-800 ring-1 ring-inset ring-amber-600/20">
                            {{ $pendingCount }}
                        </span>
                    @endif
                </a>
            @endforeach
        </div>

        @can('create', \App\Models\WalletLoadRequest::class)
            <a href="{{ route('wallet.requests.create') }}"
               class="inline-flex shrink-0 items-center gap-2 rounded-lg bg-blue-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                Request Load
            </a>
        @endcan
    </x-slot>

    <x-admin.flash />

    @if ($errors->has('wallet'))
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            {{ $errors->first('wallet') }}
        </div>
    @endif

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
        @include('wallet.requests._table')
    </div>
</x-app-layout>
