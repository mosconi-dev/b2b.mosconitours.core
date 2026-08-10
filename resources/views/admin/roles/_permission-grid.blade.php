{{-- Expects: $sections, $sectionLabels. Must sit inside a form wrapped in the
     x-data="rolePermissions({ selected: [...] })" Alpine component. --}}
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
