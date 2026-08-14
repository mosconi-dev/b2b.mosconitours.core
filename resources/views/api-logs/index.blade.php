<x-app-layout>
    <x-slot name="header">
        <x-page-heading title="API Logs" subtitle="Outbound supplier requests and responses.">
            <x-slot name="icon">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.875 1.875 0 010 3.75H5.625a1.875 1.875 0 010-3.75z" />
                </svg>
            </x-slot>
        </x-page-heading>
    </x-slot>

    <x-slot name="actions">
        @php
            $suppliers = ['' => 'All suppliers'] + \App\Enums\Supplier::options();
            $currentSupplier = $supplier?->value ?? '';
            $tabs = ['' => 'All', 'authenticate' => 'Auth', 'search' => 'Search'];
        @endphp

        {{-- Supplier filter --}}
        <div class="inline-flex rounded-lg border border-gray-200 bg-white p-1 shadow-sm">
            @foreach ($suppliers as $value => $label)
                <a href="{{ route('api-logs', array_filter(['supplier' => $value, 'type' => $type])) }}"
                   @class([
                       'rounded-md px-3.5 py-1.5 text-sm font-medium transition',
                       'bg-brand-800 text-white shadow-sm' => (string) $currentSupplier === (string) $value,
                       'text-gray-500 hover:text-gray-700' => (string) $currentSupplier !== (string) $value,
                   ])>
                    {{ $label }}
                </a>
            @endforeach
        </div>

        {{-- Type filter --}}
        <div class="inline-flex rounded-lg border border-gray-200 bg-white p-1 shadow-sm">
            @foreach ($tabs as $value => $label)
                <a href="{{ route('api-logs', array_filter(['supplier' => $currentSupplier, 'type' => $value])) }}"
                   @class([
                       'rounded-md px-3.5 py-1.5 text-sm font-medium transition',
                       'bg-brand-800 text-white shadow-sm' => (string) $type === (string) $value,
                       'text-gray-500 hover:text-gray-700' => (string) $type !== (string) $value,
                   ])>
                    {{ $label }}
                </a>
            @endforeach
        </div>
    </x-slot>

    @include('api-logs._table', ['logs' => $logs])
</x-app-layout>
