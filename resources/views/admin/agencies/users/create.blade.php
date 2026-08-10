<x-app-layout>
    <x-slot name="header">
        <x-page-heading title="New User" :subtitle="$agency->name" />
    </x-slot>

    <x-slot name="back">
        <a href="{{ route('admin.agencies.show', $agency) }}" class="text-sm font-medium text-gray-500 hover:text-gray-700">&larr; Back to {{ $agency->name }}</a>
    </x-slot>

    <div class="max-w-2xl">
        <x-admin.flash />

        <form method="POST" action="{{ route('admin.agencies.users.store', $agency) }}"
              class="space-y-6 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf

            <p class="rounded-lg bg-gray-50 px-3.5 py-2 text-sm text-gray-600">
                This user will belong to <span class="font-semibold text-brand-900">{{ $agency->name }}</span>
                ({{ $agency->code }}).
            </p>

            <div>
                <x-input-label for="name" value="Name" />
                <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name')" required autofocus />
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="email" value="Email" />
                <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" :value="old('email')" required />
                <x-input-error :messages="$errors->get('email')" class="mt-2" />
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <x-input-label for="password" value="Password" />
                    <x-text-input id="password" name="password" type="password" class="mt-1 block w-full" required autocomplete="new-password" />
                    <x-input-error :messages="$errors->get('password')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="password_confirmation" value="Confirm Password" />
                    <x-text-input id="password_confirmation" name="password_confirmation" type="password" class="mt-1 block w-full" required autocomplete="new-password" />
                </div>
            </div>

            <div>
                <x-input-label value="Roles" />
                @if ($roles->isEmpty())
                    <p class="mt-1 rounded-lg border border-dashed border-gray-300 px-3.5 py-3 text-sm text-gray-500">
                        This agency owns no roles yet, so there is nothing to assign. You can create the
                        user now and give them a role once one exists.
                    </p>
                @else
                    <p class="mb-2 text-xs text-gray-500">Only roles owned by this agency can be assigned.</p>
                    @include('admin.users._roles', ['selectedRoleIds' => old('roles', [])])
                @endif
                <x-input-error :messages="$errors->get('roles')" class="mt-2" />
            </div>

            <div class="flex items-center justify-end gap-3 border-t border-gray-100 pt-5">
                <a href="{{ route('admin.agencies.show', $agency) }}" class="text-sm font-medium text-gray-600 hover:text-gray-800">Cancel</a>
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700">
                    Create User
                </button>
            </div>
        </form>
    </div>
</x-app-layout>
