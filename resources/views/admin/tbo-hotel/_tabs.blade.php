{{-- Shared by the two TBO Hotel admin pages. The left nav carries one entry per RBAC
     module, so the second page is reachable from the first rather than from the nav. --}}
@php
    $tabs = [
        ['label' => 'Catalogue', 'route' => 'admin.hotel-catalogue.index', 'can' => 'supplier.tbohotel.view'],
        ['label' => 'Settings', 'route' => 'admin.tbo-hotel.settings', 'can' => 'supplier.tbohotel.view'],
    ];
@endphp

<div class="mb-6 flex gap-1 border-b border-gray-200">
    @foreach ($tabs as $tab)
        @can($tab['can'])
            @php
                $active = request()->routeIs($tab['route']);
                $classes = $active
                    ? 'border-brand-700 text-brand-900'
                    : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700';
            @endphp
            <a href="{{ route($tab['route']) }}"
               class="-mb-px border-b-2 px-4 py-2.5 text-sm font-medium transition {{ $classes }}"
               @if ($active) aria-current="page" @endif>
                {{ $tab['label'] }}
            </a>
        @endcan
    @endforeach
</div>
