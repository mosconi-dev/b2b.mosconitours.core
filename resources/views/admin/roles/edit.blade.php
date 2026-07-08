<x-app-layout>
    <x-slot name="header">
        <div>
            <a href="{{ route('admin.roles.index') }}" class="text-sm font-medium text-gray-500 hover:text-gray-700">&larr; Back to roles</a>
            <div class="mt-1 flex items-center gap-2">
                <h1 class="text-2xl font-bold tracking-tight text-brand-900">{{ $role->label }}</h1>
                @if ($role->is_system)
                    <span class="inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700 ring-1 ring-inset ring-amber-600/20">Built-in</span>
                @endif
            </div>
            <p class="mt-1 font-mono text-xs text-gray-400">{{ $role->name }}</p>
        </div>
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

            <div class="space-y-8 p-6">
                @foreach (['administration', 'travel_operations'] as $sectionKey)
                    @php $modules = $sections[$sectionKey] ?? []; @endphp
                    @continue(empty($modules))
                    <div>
                        <h3 class="mb-3 text-[11px] font-semibold uppercase tracking-wider text-gray-400">{{ $sectionLabels[$sectionKey] }}</h3>
                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            @foreach ($modules as $module)
                                <div class="rounded-lg border border-gray-200 p-4">
                                    <label class="flex items-center gap-2 border-b border-gray-100 pb-2">
                                        <input type="checkbox"
                                               @change="toggleGroup(@js($module['ids']))"
                                               :checked="allChecked(@js($module['ids']))"
                                               x-effect="$el.indeterminate = someChecked(@js($module['ids']))"
                                               class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                        <span class="text-sm font-semibold text-brand-900">{{ $module['label'] }}</span>
                                        @unless ($module['enabled'])
                                            <span class="inline-flex items-center rounded-full bg-gray-100 px-1.5 py-0.5 text-[10px] font-medium text-gray-500 ring-1 ring-inset ring-gray-500/20">Disabled</span>
                                        @endunless
                                    </label>
                                    <div class="mt-3 grid grid-cols-2 gap-2">
                                        @foreach ($module['permissions'] as $perm)
                                            <label class="flex items-center gap-2 text-sm text-gray-700">
                                                <input type="checkbox" name="permissions[]" value="{{ $perm['id'] }}"
                                                       x-model.number="selected"
                                                       class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                                {{ $perm['label'] }}
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </form>
    </div>
</x-app-layout>
