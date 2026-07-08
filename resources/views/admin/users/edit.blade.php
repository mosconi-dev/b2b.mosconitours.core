<x-app-layout>
    <x-slot name="header">
        <div>
            <a href="{{ route('admin.users.index') }}" class="text-sm font-medium text-gray-500 hover:text-gray-700">&larr; Back to users</a>
            <h1 class="mt-1 text-2xl font-bold tracking-tight text-brand-900">Edit User</h1>
            <p class="mt-1 text-sm text-gray-500">{{ $user->email }}</p>
        </div>
    </x-slot>

    <div class="max-w-2xl space-y-6">
        <x-admin.flash />

        {{-- Details + roles --}}
        <form method="POST" action="{{ route('admin.users.update', $user) }}"
              class="space-y-6 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf
            @method('PUT')

            <h2 class="text-base font-semibold text-brand-900">Details</h2>

            <div>
                <x-input-label for="name" value="Name" />
                <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name', $user->name)" required />
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="email" value="Email" />
                <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" :value="old('email', $user->email)" required />
                <x-input-error :messages="$errors->get('email')" class="mt-2" />
            </div>

            <div>
                <x-input-label value="Roles" />
                @include('admin.users._roles', ['selectedRoleIds' => old('roles', $user->roles->pluck('id')->all())])
                <x-input-error :messages="$errors->get('roles')" class="mt-2" />
            </div>

            <div class="flex items-center justify-end gap-3 border-t border-gray-100 pt-5">
                <a href="{{ route('admin.users.index') }}" class="text-sm font-medium text-gray-600 hover:text-gray-800">Cancel</a>
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700">
                    Save Changes
                </button>
            </div>
        </form>

        {{-- Reset password --}}
        <form method="POST" action="{{ route('admin.users.password', $user) }}"
              class="space-y-6 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf
            @method('PUT')

            <div>
                <h2 class="text-base font-semibold text-brand-900">Reset Password</h2>
                <p class="mt-1 text-sm text-gray-500">Set a new password for this user.</p>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <x-input-label for="password" value="New Password" />
                    <x-text-input id="password" name="password" type="password" class="mt-1 block w-full" required autocomplete="new-password" />
                    <x-input-error :messages="$errors->get('password')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="password_confirmation" value="Confirm Password" />
                    <x-text-input id="password_confirmation" name="password_confirmation" type="password" class="mt-1 block w-full" required autocomplete="new-password" />
                </div>
            </div>

            <div class="flex justify-end border-t border-gray-100 pt-5">
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm transition hover:bg-gray-50">
                    Update Password
                </button>
            </div>
        </form>

        {{-- Delete --}}
        @can('delete', $user)
            <div class="rounded-xl border border-red-200 bg-white p-6 shadow-sm">
                <h2 class="text-base font-semibold text-red-700">Delete User</h2>
                <p class="mt-1 text-sm text-gray-500">The account is deactivated and archived (soft-deleted); its history is preserved.</p>
                <form method="POST" action="{{ route('admin.users.destroy', $user) }}" class="mt-4"
                      onsubmit="return confirm('Delete this user? Their access is revoked immediately.');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-red-700">
                        Delete User
                    </button>
                </form>
            </div>
        @endcan
    </div>
</x-app-layout>
