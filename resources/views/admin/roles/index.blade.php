<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="flex items-center gap-2.5 text-2xl font-bold tracking-tight text-brand-900">
                    <svg class="h-7 w-7 text-brand-700" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 01-1.043 3.296 3.745 3.745 0 01-3.296 1.043A3.745 3.745 0 0112 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 01-3.296-1.043 3.745 3.745 0 01-1.043-3.296A3.745 3.745 0 013 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 011.043-3.296 3.746 3.746 0 013.296-1.043A3.746 3.746 0 0112 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 013.296 1.043 3.746 3.746 0 011.043 3.296A3.745 3.745 0 0121 12z" />
                    </svg>
                    Roles
                </h1>
                <p class="mt-1 text-sm text-gray-500">Define roles and the permissions they grant.</p>
            </div>
            @can('role.create')
                <button type="button" x-data @click="$dispatch('open-modal', 'create-role')"
                        class="inline-flex shrink-0 items-center gap-2 rounded-lg bg-blue-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    New Role
                </button>
            @endcan
        </div>
    </x-slot>

    <x-admin.flash />

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($roles as $role)
            <div class="flex flex-col rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <h2 class="truncate text-base font-semibold text-brand-900">{{ $role->label }}</h2>
                        <p class="mt-0.5 font-mono text-xs text-gray-400">{{ $role->name }}</p>
                    </div>
                    @if ($role->is_system)
                        <span class="inline-flex shrink-0 items-center rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700 ring-1 ring-inset ring-amber-600/20">Built-in</span>
                    @endif
                </div>

                @if ($role->description)
                    <p class="mt-2 line-clamp-2 text-sm text-gray-500">{{ $role->description }}</p>
                @endif

                <div class="mt-4 flex items-center gap-4 text-xs text-gray-500">
                    <span><span class="font-semibold text-gray-700">{{ $role->users_count }}</span> members</span>
                    <span><span class="font-semibold text-gray-700">{{ $role->permissions_count }}</span> permissions</span>
                </div>

                <div class="mt-4 flex items-center gap-2 border-t border-gray-100 pt-4">
                    @can('update', $role)
                        <a href="{{ route('admin.roles.edit', $role) }}"
                           class="rounded-md border border-gray-300 bg-white px-2.5 py-1 text-xs font-medium text-blue-600 shadow-sm transition hover:bg-gray-50">Edit</a>
                    @endcan
                    @can('duplicate', $role)
                        <form method="POST" action="{{ route('admin.roles.duplicate', $role) }}">
                            @csrf
                            <input type="hidden" name="name" value="Copy of {{ $role->label }}">
                            <button type="submit" class="rounded-md border border-gray-300 bg-white px-2.5 py-1 text-xs font-medium text-gray-700 shadow-sm transition hover:bg-gray-50">Duplicate</button>
                        </form>
                    @endcan
                    @can('delete', $role)
                        <form method="POST" action="{{ route('admin.roles.destroy', $role) }}" class="ml-auto"
                              onsubmit="return confirm('Delete this role? Members will lose its permissions.');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="rounded-md border border-red-200 bg-white px-2.5 py-1 text-xs font-medium text-red-600 shadow-sm transition hover:bg-red-50">Delete</button>
                        </form>
                    @endcan
                </div>
            </div>
        @endforeach
    </div>

    {{-- Create role modal --}}
    @can('role.create')
        <x-modal name="create-role" focusable>
            <form method="POST" action="{{ route('admin.roles.store') }}" class="space-y-5 p-6">
                @csrf
                <h2 class="text-base font-semibold text-brand-900">Create Role</h2>

                <div>
                    <x-input-label for="role_name" value="Name" />
                    <x-text-input id="role_name" name="name" type="text" class="mt-1 block w-full" :value="old('name')" required />
                    <x-input-error :messages="$errors->get('name')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="role_description" value="Description" />
                    <x-text-input id="role_description" name="description" type="text" class="mt-1 block w-full" :value="old('description')" />
                    <x-input-error :messages="$errors->get('description')" class="mt-2" />
                </div>

                <div class="flex justify-end gap-3">
                    <button type="button" x-on:click="$dispatch('close')" class="text-sm font-medium text-gray-600 hover:text-gray-800">Cancel</button>
                    <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700">
                        Create &amp; Configure
                    </button>
                </div>
            </form>
        </x-modal>
    @endcan
</x-app-layout>
