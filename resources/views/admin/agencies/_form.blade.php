{{-- Expects: $types (array<AgencyType>), $parents (Collection<Agency>), $agency (Agency|null) --}}
@php
    $agency = $agency ?? null;
@endphp

<div>
    <x-input-label for="name" value="Name" />
    <x-text-input id="name" name="name" type="text" class="mt-1 block w-full"
                  :value="old('name', $agency?->name)" required autofocus />
    <x-input-error :messages="$errors->get('name')" class="mt-2" />
</div>

<div>
    <x-input-label for="code" value="Code" />
    @if ($agency)
        <p class="mt-1 rounded-lg bg-gray-50 px-3.5 py-2 font-mono text-sm text-gray-600">{{ $agency->code }}</p>
        <p class="mt-1 text-xs text-gray-500">The code is permanent — reports and exports reference it.</p>
    @else
        <x-text-input id="code" name="code" type="text" class="mt-1 block w-full font-mono"
                      :value="old('code')" placeholder="Leave blank to generate from the name" />
        <p class="mt-1 text-xs text-gray-500">Permanent once created.</p>
        <x-input-error :messages="$errors->get('code')" class="mt-2" />
    @endif
</div>

<div>
    <x-input-label for="type" value="Type" />
    <select id="type" name="type" required
            class="mt-1 block w-full rounded-lg border-gray-300 py-2 pl-3.5 pr-8 text-sm text-gray-700 shadow-sm focus:border-blue-500 focus:ring-blue-500">
        @foreach ($types as $type)
            <option value="{{ $type->value }}" @selected(old('type', $agency?->type?->value) === $type->value)>{{ $type->label() }}</option>
        @endforeach
    </select>
    <p class="mt-1 text-xs text-gray-500">Descriptive only — access comes from the roles you assign, not the type.</p>
    <x-input-error :messages="$errors->get('type')" class="mt-2" />
</div>

<div>
    <x-input-label for="parent_id" value="Reports to" />
    <select id="parent_id" name="parent_id"
            class="mt-1 block w-full rounded-lg border-gray-300 py-2 pl-3.5 pr-8 text-sm text-gray-700 shadow-sm focus:border-blue-500 focus:ring-blue-500">
        <option value="">None (independent)</option>
        @foreach ($parents as $parent)
            <option value="{{ $parent->id }}" @selected((int) old('parent_id', $agency?->parent_id) === $parent->id)>{{ $parent->label() }}</option>
        @endforeach
    </select>
    <p class="mt-1 text-xs text-gray-500">For reporting and markups. It grants no access — permissions never inherit.</p>
    <x-input-error :messages="$errors->get('parent_id')" class="mt-2" />
</div>

<div class="border-t border-gray-100 pt-5">
    <h3 class="text-sm font-semibold text-brand-900">Contact</h3>
</div>

<div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
    <div>
        <x-input-label for="contact_email" value="Email" />
        <x-text-input id="contact_email" name="contact_email" type="email" class="mt-1 block w-full"
                      :value="old('contact_email', $agency?->contact_email)" />
        <x-input-error :messages="$errors->get('contact_email')" class="mt-2" />
    </div>
    <div>
        <x-input-label for="contact_phone" value="Contact number" />
        <x-text-input id="contact_phone" name="contact_phone" type="tel" class="mt-1 block w-full"
                      :value="old('contact_phone', $agency?->contact_phone)" />
        <x-input-error :messages="$errors->get('contact_phone')" class="mt-2" />
    </div>
</div>

<div>
    <x-input-label for="address" value="Address" />
    <textarea id="address" name="address" rows="3"
              class="mt-1 block w-full rounded-lg border-gray-300 text-sm text-gray-700 shadow-sm focus:border-blue-500 focus:ring-blue-500">{{ old('address', $agency?->address) }}</textarea>
    <x-input-error :messages="$errors->get('address')" class="mt-2" />
</div>

<div>
    <x-input-label value="Logo" />
    @include('admin.agencies._logo-dropzone')
    <x-input-error :messages="$errors->get('logo')" class="mt-2" />
</div>
