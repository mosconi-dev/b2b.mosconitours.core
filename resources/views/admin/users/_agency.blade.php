{{-- Expects: $agencies (Collection<Agency>), $selectedAgencyId (int|null), $actor (User) --}}
@if ($actor->isPlatformStaff())
    <x-input-label for="agency_id" value="Agency" />
    <select id="agency_id" name="agency_id"
            class="mt-1 block w-full rounded-lg border-gray-300 py-2 pl-3.5 pr-8 text-sm text-gray-700 shadow-sm focus:border-blue-500 focus:ring-blue-500">
        <option value="">Platform staff (no agency)</option>
        @foreach ($agencies as $agency)
            <option value="{{ $agency->id }}" @selected((int) $selectedAgencyId === $agency->id)>{{ $agency->label() }}</option>
        @endforeach
    </select>
    <p class="mt-1 text-xs text-gray-500">
        The branch this user works in. Their roles apply there only — platform staff are not tied to a branch.
    </p>
    <x-input-error :messages="$errors->get('agency_id')" class="mt-2" />
@else
    {{-- An agency member can only ever manage people in their own agency, so there is
         nothing to choose. The service forces this server-side regardless of the form. --}}
    <x-input-label value="Agency" />
    <p class="mt-1 rounded-lg bg-gray-50 px-3.5 py-2 text-sm text-gray-600">{{ $actor->agency?->name }}</p>
    <p class="mt-1 text-xs text-gray-500">Users you create belong to your agency.</p>
@endif
