{{-- Expects: $roles (Collection<Role>), $selectedRoleIds (array<int>) --}}
<div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
    @foreach ($roles as $role)
        <label class="flex items-start gap-2 rounded-lg border border-gray-200 p-3 transition hover:bg-gray-50">
            <input type="checkbox" name="roles[]" value="{{ $role->id }}"
                   @checked(in_array($role->id, $selectedRoleIds))
                   class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
            <span class="min-w-0">
                <span class="block text-sm font-medium text-gray-800">{{ $role->label }}</span>
                @if ($role->description)
                    <span class="block text-xs text-gray-500">{{ $role->description }}</span>
                @endif
            </span>
        </label>
    @endforeach
</div>
