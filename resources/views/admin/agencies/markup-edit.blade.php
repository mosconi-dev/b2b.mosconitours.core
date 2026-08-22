<x-app-layout>
    <x-slot name="header">
        <x-page-heading title="Edit markup rule" subtitle="{{ $agency->label() }} — {{ $rule->serviceLine() }}">
            <x-slot name="icon">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zM19.5 12v6.75A2.25 2.25 0 0117.25 21H5.25A2.25 2.25 0 013 18.75V6.75A2.25 2.25 0 015.25 4.5H12" />
                </svg>
            </x-slot>
        </x-page-heading>
    </x-slot>

    <div class="max-w-5xl space-y-6">
        <x-admin.flash />

        <div class="rounded-xl border border-gray-200 bg-white shadow-sm">
            <div class="border-b border-gray-100 px-6 py-4">
                <h2 class="text-base font-semibold text-brand-900">{{ $rule->serviceLine() }}</h2>
                <p class="mt-1 text-sm text-gray-500">
                    Saving changes what your next search is charged. Bookings already made keep the rule
                    as it was — each one stored its own copy — so this does not reach backwards.
                    @unless ($rule->is_active)
                        <br><span class="font-medium text-amber-700">This rule is switched off, and saving leaves it off.</span>
                    @endunless
                </p>
            </div>

            <form method="POST" action="{{ route('admin.agencies.markup.rules.update', [$agency, $rule]) }}" class="px-6 py-5">
                @csrf @method('PUT')

                @include('admin.pricing._field-guide', ['audience' => 'agency'])

                @include('admin.pricing._rule-fields', [
                    ...$options,
                    'rule' => $rule,
                    'audience' => 'agency',
                    'idPrefix' => 'ag-',
                ])

                @if ($errors->any())
                    <ul class="mt-3 space-y-1 text-sm font-medium text-red-700">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                @endif

                <div class="mt-4 flex items-center gap-3">
                    <button type="submit" class="rounded-lg bg-brand-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-brand-800">
                        Save changes
                    </button>
                    <a href="{{ route('admin.agencies.show', $agency) }}" class="text-sm font-medium text-gray-500 hover:text-gray-700">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
