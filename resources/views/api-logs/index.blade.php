<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="flex items-center gap-2.5 text-2xl font-bold tracking-tight text-brand-900">
                    <svg class="h-7 w-7 text-brand-700" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.875 1.875 0 010 3.75H5.625a1.875 1.875 0 010-3.75z" />
                    </svg>
                    API Logs
                </h1>
                <p class="mt-1 text-sm text-gray-500">TBO Air authentication &amp; search requests and responses.</p>
            </div>

            {{-- Type filter --}}
            <div class="inline-flex rounded-lg border border-gray-200 bg-white p-1 shadow-sm">
                @php
                    $tabs = ['' => 'All', 'authenticate' => 'Auth', 'search' => 'Search'];
                @endphp
                @foreach ($tabs as $value => $label)
                    <a href="{{ route('api-logs', array_filter(['type' => $value])) }}"
                       @class([
                           'rounded-md px-3.5 py-1.5 text-sm font-medium transition',
                           'bg-brand-800 text-white shadow-sm' => (string) $type === (string) $value,
                           'text-gray-500 hover:text-gray-700' => (string) $type !== (string) $value,
                       ])>
                        {{ $label }}
                    </a>
                @endforeach
            </div>
        </div>
    </x-slot>

    @include('api-logs._table', ['logs' => $logs])
</x-app-layout>
