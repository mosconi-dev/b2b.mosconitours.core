<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="flex items-center gap-2.5 text-2xl font-bold tracking-tight text-brand-900">
                    <svg class="h-7 w-7 text-brand-700" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0112 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 013 12c0-1.605.42-3.113 1.157-4.418" />
                    </svg>
                    Dashboard
                </h1>
                <p class="mt-1 text-sm text-gray-500">Welcome back — manage your bookings, wallet, and account.</p>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <button class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3.5 py-2 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                    </svg>
                    Export
                </button>
                <button class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    New Booking
                </button>
            </div>
        </div>
    </x-slot>

    <!-- Stat cards -->
    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
        @php
            $stats = [
                ['label' => 'Active Bookings', 'value' => '12', 'sub' => '+3 this week', 'tone' => 'text-emerald-600'],
                ['label' => 'Pending Payments', 'value' => '4', 'sub' => '₱ 86,400 due', 'tone' => 'text-amber-600'],
                ['label' => 'Wallet Balance', 'value' => '₱ 152,300', 'sub' => 'Available', 'tone' => 'text-gray-500'],
                ['label' => 'Messages', 'value' => '8', 'sub' => '2 unread', 'tone' => 'text-blue-600'],
            ];
        @endphp
        @foreach ($stats as $stat)
            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <p class="text-sm font-medium text-gray-500">{{ $stat['label'] }}</p>
                <p class="mt-2 text-2xl font-bold tracking-tight text-brand-900">{{ $stat['value'] }}</p>
                <p class="mt-1 text-xs font-medium {{ $stat['tone'] }}">{{ $stat['sub'] }}</p>
            </div>
        @endforeach
    </div>

    <!-- Recent bookings -->
    <div class="mt-8 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
        <div class="flex flex-col gap-3 border-b border-gray-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
            <h2 class="text-base font-semibold text-brand-900">Recent Bookings</h2>
            <div class="relative w-full sm:w-72">
                <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-gray-400">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                    </svg>
                </span>
                <input type="text" placeholder="Search bookings..."
                       class="w-full rounded-lg border-gray-300 py-2 pl-9 pr-3 text-sm text-gray-700 placeholder-gray-400 focus:border-blue-500 focus:ring-blue-500" />
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-100 text-sm">
                <thead>
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <th class="px-5 py-3">Reference</th>
                        <th class="px-5 py-3">Type</th>
                        <th class="px-5 py-3">Route</th>
                        <th class="px-5 py-3">Status</th>
                        <th class="px-5 py-3 text-right">Amount</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @php
                        $bookings = [
                            ['ref' => 'BK-10293', 'type' => 'Flight', 'route' => 'MNL → CEB', 'status' => 'Confirmed', 'badge' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20', 'amount' => '₱ 7,450'],
                            ['ref' => 'BK-10288', 'type' => 'Hotel',  'route' => 'Boracay, 3 nights', 'status' => 'Pending', 'badge' => 'bg-amber-50 text-amber-700 ring-amber-600/20', 'amount' => '₱ 18,200'],
                            ['ref' => 'BK-10281', 'type' => 'Package','route' => 'Palawan Getaway', 'status' => 'Confirmed', 'badge' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20', 'amount' => '₱ 42,900'],
                            ['ref' => 'BK-10275', 'type' => 'Flight', 'route' => 'MNL → SIN', 'status' => 'Cancelled', 'badge' => 'bg-red-50 text-red-700 ring-red-600/20', 'amount' => '₱ 12,300'],
                        ];
                    @endphp
                    @foreach ($bookings as $b)
                        <tr class="transition hover:bg-gray-50">
                            <td class="px-5 py-3.5 font-medium text-blue-600">{{ $b['ref'] }}</td>
                            <td class="px-5 py-3.5 text-gray-600">{{ $b['type'] }}</td>
                            <td class="px-5 py-3.5 text-gray-900">{{ $b['route'] }}</td>
                            <td class="px-5 py-3.5">
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $b['badge'] }}">
                                    {{ $b['status'] }}
                                </span>
                            </td>
                            <td class="px-5 py-3.5 text-right font-medium text-gray-900">{{ $b['amount'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="flex items-center justify-between border-t border-gray-100 px-5 py-3 text-sm text-gray-500">
            <span><span class="font-medium text-gray-700">1 – 4</span> of <span class="font-medium text-gray-700">4</span> bookings</span>
            <a href="#" class="font-medium text-blue-600 hover:text-blue-700">View all →</a>
        </div>
    </div>
</x-app-layout>
