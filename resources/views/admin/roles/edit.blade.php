<x-app-layout>
    <x-slot name="header">
        <x-page-heading :title="$role->label" :subtitle="$role->name">
            @if ($role->agency)
                <span class="inline-flex shrink-0 items-center rounded-full bg-sky-50 px-2 py-0.5 text-xs font-medium text-sky-700 ring-1 ring-inset ring-sky-600/20">{{ $role->agency->name }}</span>
            @endif
            @if ($role->is_system)
                <span class="inline-flex shrink-0 items-center rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700 ring-1 ring-inset ring-amber-600/20">Built-in</span>
            @endif
        </x-page-heading>
    </x-slot>

    <x-slot name="back">
        <a href="{{ route('admin.roles.index') }}" class="text-sm font-medium text-gray-500 hover:text-gray-700">&larr; Back to roles</a>
    </x-slot>

    <div class="space-y-6">
        <x-admin.flash />

        {{-- Details --}}
        <form method="POST" action="{{ route('admin.roles.update', $role) }}"
              class="max-w-2xl space-y-5 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf
            @method('PUT')
            <h2 class="text-base font-semibold text-brand-900">Details</h2>

            <div>
                <x-input-label for="name" value="Name" />
                <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name', $role->label)" required />
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="description" value="Description" />
                <x-text-input id="description" name="description" type="text" class="mt-1 block w-full" :value="old('description', $role->description)" />
                <x-input-error :messages="$errors->get('description')" class="mt-2" />
            </div>

            <div class="flex justify-end border-t border-gray-100 pt-5">
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm transition hover:bg-gray-50">
                    Save Details
                </button>
            </div>
        </form>

        {{-- Permission grid --}}
        <form method="POST" action="{{ route('admin.roles.permissions', $role) }}"
              x-data="rolePermissions({ selected: @js($selected) })"
              class="rounded-xl border border-gray-200 bg-white shadow-sm">
            @csrf
            @method('PUT')

            <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4">
                <h2 class="text-base font-semibold text-brand-900">Permissions</h2>
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700">
                    Save Permissions
                </button>
            </div>

            @if (! empty($unmanageable))
                <div class="border-b border-gray-100 bg-amber-50/60 px-6 py-4">
                    <p class="text-sm font-medium text-amber-800">Granted beyond your own access</p>
                    <p class="mt-1 text-xs text-amber-700">
                        This role also holds {{ implode(', ', $unmanageable) }}. You cannot change these
                        because you do not hold them yourself — they are kept as-is when you save.
                    </p>
                </div>
            @endif

            @include('admin.roles._permission-grid')
        </form>
    </div>
</x-app-layout>
