<x-app-layout>
    <x-slot name="header">
        <div>
            <a href="{{ route('admin.users.index') }}" class="text-sm font-medium text-gray-500 hover:text-gray-700">&larr; Back to users</a>
            <h1 class="mt-1 flex items-center gap-2.5 text-2xl font-bold tracking-tight text-brand-900">
                <svg class="h-7 w-7 text-brand-700" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.875 1.875 0 010 3.75H5.625a1.875 1.875 0 010-3.75z" />
                </svg>
                {{ $user->name }} — Logs
            </h1>
            <p class="mt-1 text-sm text-gray-500">{{ $user->email }} · What this user is doing in the app.</p>

            <div class="mt-4 flex flex-wrap items-center gap-3">
                {{-- Section tabs --}}
                <div class="inline-flex rounded-lg border border-gray-200 bg-white p-1 shadow-sm">
                    <a href="{{ route('admin.users.logs', $user) }}"
                       @class([
                           'rounded-md px-3.5 py-1.5 text-sm font-medium transition',
                           'bg-brand-800 text-white shadow-sm' => $tab === 'api',
                           'text-gray-500 hover:text-gray-700' => $tab !== 'api',
                       ])>API calls</a>
                    <a href="{{ route('admin.users.logs', ['user' => $user, 'tab' => 'activity']) }}"
                       @class([
                           'rounded-md px-3.5 py-1.5 text-sm font-medium transition',
                           'bg-brand-800 text-white shadow-sm' => $tab === 'activity',
                           'text-gray-500 hover:text-gray-700' => $tab !== 'activity',
                       ])>Activity</a>
                </div>

                {{-- Type filter (API calls only) --}}
                @if ($tab === 'api')
                    <div class="inline-flex rounded-lg border border-gray-200 bg-white p-1 shadow-sm">
                        @php
                            $tabs = ['' => 'All', 'authenticate' => 'Auth', 'search' => 'Search'];
                        @endphp
                        @foreach ($tabs as $value => $label)
                            <a href="{{ route('admin.users.logs', ['user' => $user] + array_filter(['type' => $value])) }}"
                               @class([
                                   'rounded-md px-3 py-1.5 text-sm font-medium transition',
                                   'bg-gray-100 text-brand-900' => (string) $type === (string) $value,
                                   'text-gray-500 hover:text-gray-700' => (string) $type !== (string) $value,
                               ])>{{ $label }}</a>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </x-slot>

    @if ($tab === 'activity')
        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
            @if ($entries->isEmpty())
                <div class="p-12 text-center">
                    <p class="text-sm font-medium text-brand-900">No actions recorded yet</p>
                    <p class="mt-1 text-sm text-gray-500">Sign-ins and any create / update / delete actions this user makes will appear here.</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100 text-sm">
                        <thead>
                            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                <th class="px-5 py-3">When</th>
                                <th class="px-5 py-3">Action</th>
                                <th class="px-5 py-3">Detail</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($entries as $a)
                                @php
                                    [$cat, $catClass] = match (true) {
                                        str_contains($a->event, 'created') => ['created', 'bg-emerald-50 text-emerald-700 ring-emerald-600/20'],
                                        str_contains($a->event, 'deleted') => ['deleted', 'bg-red-50 text-red-700 ring-red-600/20'],
                                        str_starts_with($a->event, 'auth.') => [str_replace('auth.', '', $a->event), 'bg-gray-50 text-gray-600 ring-gray-500/20'],
                                        default => ['updated', 'bg-blue-50 text-blue-700 ring-blue-600/20'],
                                    };
                                @endphp
                                <tr class="transition hover:bg-gray-50">
                                    <td class="whitespace-nowrap px-5 py-3 text-gray-500">{{ $a->created_at?->format('M j, H:i:s') }}</td>
                                    <td class="px-5 py-3">
                                        <div class="flex items-center gap-2">
                                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium capitalize ring-1 ring-inset {{ $catClass }}">{{ $cat }}</span>
                                            <span class="font-medium text-brand-900">{{ $a->label() }}</span>
                                        </div>
                                    </td>
                                    <td class="px-5 py-3 text-gray-500">{{ $a->description ?? $a->target() ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="border-t border-gray-100 px-5 py-3">
                    {{ $entries->links() }}
                </div>
            @endif
        </div>
    @else
        @include('api-logs._table', ['logs' => $logs, 'showUser' => false])
    @endif
</x-app-layout>
