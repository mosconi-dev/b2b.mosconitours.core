<x-app-layout>
    <x-slot name="header">
        <x-page-heading title="New Role" :subtitle="$agency->name" />
    </x-slot>

    <x-slot name="back">
        <a href="{{ route('admin.agencies.show', ['agency' => $agency, 'tab' => 'roles']) }}" class="text-sm font-medium text-gray-500 hover:text-gray-700">&larr; Back to {{ $agency->name }}</a>
    </x-slot>

    <div class="space-y-6">
        <x-admin.flash />

        {{-- One form: details + permissions, so creating a role never leaves the agency. --}}
        <form method="POST" action="{{ route('admin.agencies.roles.store', $agency) }}"
              x-data="rolePermissions({ selected: @js($selected) })"
              class="space-y-6">
            @csrf

            <div class="max-w-2xl space-y-5 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                <h2 class="text-base font-semibold text-brand-900">Details</h2>

                <p class="rounded-lg bg-gray-50 px-3.5 py-2 text-sm text-gray-600">
                    Owned by <span class="font-semibold text-brand-900">{{ $agency->name }}</span>
                    ({{ $agency->code }}) — only this agency's users can be given it.
                </p>

                <div>
                    <x-input-label for="name" value="Name" />
                    <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name')" required autofocus />
                    <x-input-error :messages="$errors->get('name')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="description" value="Description" />
                    <x-text-input id="description" name="description" type="text" class="mt-1 block w-full" :value="old('description')" />
                    <x-input-error :messages="$errors->get('description')" class="mt-2" />
                </div>
            </div>

            <div class="rounded-xl border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-100 px-6 py-4">
                    <h2 class="text-base font-semibold text-brand-900">Permissions</h2>
                    <p class="mt-1 text-xs text-gray-500">You can only grant permissions you hold yourself.</p>
                </div>

                @include('admin.roles._permission-grid')

                <x-input-error :messages="$errors->get('permissions')" class="px-6 pb-4" />
            </div>

            <div class="flex items-center justify-end gap-3">
                <a href="{{ route('admin.agencies.show', ['agency' => $agency, 'tab' => 'roles']) }}" class="text-sm font-medium text-gray-600 hover:text-gray-800">Cancel</a>
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700">
                    Create Role
                </button>
            </div>
        </form>
    </div>
</x-app-layout>
