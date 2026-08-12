{{--
    Three selects for one date on the passenger at `i` in the guest-details x-for.

    Not a calendar: every date on a passenger is years from today — a birth date
    decades back, a passport a decade either side — and a picker makes an agent page
    through hundreds of months to reach one.

    @param string $field     passenger key: dateOfBirth | documentExpiry | documentIssueDate
    @param string $label     static label, when the wording never changes
    @param string $labelExpr Alpine expression for a label that depends on the route
    @param bool   $required  show the asterisk
--}}
@php
    $labelBinding = $labelExpr ?? null;
@endphp
<div>
    <label class="mb-1 block text-xs font-medium text-gray-600">
        @if ($labelBinding)
            <span x-text="{{ $labelBinding }}"></span>
        @else
            {{ $label }}
        @endif
        @if ($required ?? false)
            <span class="text-red-500">*</span>
        @endif
    </label>

    <div class="grid grid-cols-3 gap-2">
        <select :value="datePart(i, '{{ $field }}', 'm')"
                @change="setDatePart(i, '{{ $field }}', 'm', $event.target.value)"
                aria-label="Month"
                class="w-full rounded-lg border-gray-300 text-sm focus:border-blue-500 focus:ring-blue-500"
                :class="datePart(i, '{{ $field }}', 'm') ? 'text-gray-900' : 'text-gray-400'">
            <option value="" class="text-gray-400">Month</option>
            <template x-for="mo in dobMonths" :key="mo.value">
                <option :value="mo.value" x-text="mo.name" class="text-gray-900"></option>
            </template>
        </select>

        <select :value="datePart(i, '{{ $field }}', 'd')"
                @change="setDatePart(i, '{{ $field }}', 'd', $event.target.value)"
                aria-label="Day"
                class="w-full rounded-lg border-gray-300 text-sm focus:border-blue-500 focus:ring-blue-500"
                :class="datePart(i, '{{ $field }}', 'd') ? 'text-gray-900' : 'text-gray-400'">
            <option value="" class="text-gray-400">Day</option>
            <template x-for="day in dateDays(i, '{{ $field }}')" :key="day">
                <option :value="day" x-text="Number(day)" class="text-gray-900"></option>
            </template>
        </select>

        <select :value="datePart(i, '{{ $field }}', 'y')"
                @change="setDatePart(i, '{{ $field }}', 'y', $event.target.value)"
                aria-label="Year"
                class="w-full rounded-lg border-gray-300 text-sm focus:border-blue-500 focus:ring-blue-500"
                :class="datePart(i, '{{ $field }}', 'y') ? 'text-gray-900' : 'text-gray-400'">
            <option value="" class="text-gray-400">Year</option>
            <template x-for="yr in dateYears('{{ $field }}')" :key="yr">
                <option :value="yr" x-text="yr" class="text-gray-900"></option>
            </template>
        </select>
    </div>

    <p x-show="dateError(i, '{{ $field }}')" x-cloak class="mt-1 text-xs text-red-600"
       x-text="dateError(i, '{{ $field }}')"></p>
</div>
