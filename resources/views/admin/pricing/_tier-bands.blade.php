{{--
    The tier table editor.

    Only ever shown when Type is Tiered — every other type keeps its numbers in the
    Amount box, and StorePricingRuleRequest throws this table away for them, so a rule
    switched back to a percentage cannot leave a table behind that nothing computes.

    Its own Alpine scope, nested inside the form's: `calcType` is read from the parent,
    `bands` and `mode` belong here. The rows are posted as params[bands][i][...], which
    is the shape TieredBands reads out of the column.

    Expects: $tierModes, $tierUnits, $tierUnitsByProduct, $tierUnitDefaults,
    $bandCalcTypes, and `calcType` and `product` properties on the surrounding x-data.
    $span sets the grid width; $idPrefix keeps the field ids unique on a screen that
    carries more than one of these; $rule is the rule being edited, or null when adding.
--}}
@php
    $editing = $rule ?? null;

    // Two rows, because a one-band table is just that band — and a blank third row to
    // type into is added by the button rather than shipped, so the count means something.
    $tierRows = collect(old('params.bands', $editing?->params['bands'] ?? [
        ['up_to' => '', 'calc_type' => 'percentage_markup', 'value' => ''],
        ['up_to' => '', 'calc_type' => 'percentage_markup', 'value' => ''],
    ]))->values()->map(fn ($row, $i) => [
        'uid' => $i,
        'up_to' => (string) ($row['up_to'] ?? ''),
        'calc_type' => (string) ($row['calc_type'] ?? 'percentage_markup'),
        'value' => (string) ($row['value'] ?? ''),
    ])->all();
@endphp

<div class="{{ $span }}"
     x-show="calcType === 'tiered'"
     @if (old('calc_type', $editing?->calc_type->value ?? 'fixed') !== 'tiered') x-cloak @endif
     x-data="{
         bands: @js($tierRows),
         mode: @js(old('params.mode', $editing?->params['mode'] ?? 'whole')),
         unit: @js(old('params.bands_on', $editing?->params['bands_on'] ?? ($tierUnitDefaults[old('product', $editing?->product ?? '*')] ?? 'booking'))),
         unitChosen: @js(filled(old('params.bands_on', $editing?->params['bands_on'] ?? null))),
         unitByProduct: @js($tierUnitsByProduct),
         unitDefaults: @js($tierUnitDefaults),
         nextUid: {{ count($tierRows) }},
         add() { this.bands.push({ uid: this.nextUid++, up_to: '', calc_type: 'percentage_markup', value: '' }) },
         remove(uid) { this.bands = this.bands.filter(band => band.uid !== uid) },
     }"
     x-effect="
         if (! unitChosen) unit = unitDefaults[product];
         else if (! (unit in unitByProduct[product])) unit = 'booking';
     ">
    <div class="rounded-lg border border-brand-200 bg-brand-50/40 px-3 py-3">
        <div class="mb-2 flex items-baseline justify-between gap-3">
            <p class="text-xs font-semibold text-brand-900">The tier table</p>
            <p class="text-[11px] text-gray-500">Leave the last band's limit empty — it prices everything above.</p>
        </div>

        {{-- The mode first, because it changes what the same numbers mean. --}}
        <div class="mb-3">
            <label for="{{ $idPrefix ?? '' }}tier_mode" class="mb-1 block text-xs font-medium text-gray-600">How the bands charge</label>
            <select id="{{ $idPrefix ?? '' }}tier_mode" name="params[mode]" x-model="mode"
                    class="w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                @foreach ($tierModes as $value => $text)
                    <option value="{{ $value }}" @selected(old('params.mode', $editing?->params['mode'] ?? 'whole') === (string) $value)>{{ $text }}</option>
                @endforeach
            </select>

            @foreach ($tierModes as $value => $text)
                <p class="mt-1 text-[11px] leading-relaxed text-gray-500"
                   x-show="mode === @js($value)"
                   @if (old('params.mode', $editing?->params['mode'] ?? 'whole') !== (string) $value) x-cloak @endif>
                    {{ \App\Enums\TierMode::from($value)->guidance() }}
                </p>
            @endforeach
        </div>

        {{-- What the bands read. A ₱30,000 fare for three is either one ₱30,000 booking
             or three ₱10,000 tickets, and the table means something different in each.
             Gated by product like Type is — head count is not what a hotel charges on —
             and it follows the product's default until somebody chooses for themselves,
             because a flight tier table means a per-ticket table to whoever writes one. --}}
        <div class="mb-3">
            <label for="{{ $idPrefix ?? '' }}tier_unit" class="mb-1 block text-xs font-medium text-gray-600">What the bands read</label>
            <select id="{{ $idPrefix ?? '' }}tier_unit" name="params[bands_on]" x-model="unit" x-on:change="unitChosen = true"
                    class="w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                @foreach ($tierUnits as $value => $text)
                    @php $unitRestriction = \App\Enums\TierUnit::from($value)->productRestriction(); @endphp
                    <option value="{{ $value }}"
                            :disabled="! (@js($value) in unitByProduct[product])">{{ $text }}@if ($unitRestriction) — {{ $unitRestriction }}@endif</option>
                @endforeach
            </select>

            @foreach ($tierUnits as $value => $text)
                <p class="mt-1 text-[11px] leading-relaxed text-gray-500" x-show="unit === @js($value)" x-cloak>
                    {{ \App\Enums\TierUnit::from($value)->guidance() }}
                </p>
            @endforeach
        </div>

        <div class="mb-1 grid grid-cols-12 gap-2 text-[11px] font-medium uppercase tracking-wide text-gray-400">
            <span class="col-span-4">Up to</span>
            <span class="col-span-5">Charged</span>
            <span class="col-span-2">Amount</span>
            <span class="col-span-1"></span>
        </div>

        <template x-for="(band, i) in bands" :key="band.uid">
            <div class="mb-2 grid grid-cols-12 items-center gap-2">
                <input type="number" step="0.01" min="0" class="col-span-4 rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500"
                       :name="'params[bands][' + i + '][up_to]'"
                       :placeholder="i === bands.length - 1 ? 'everything above' : '10000.00'"
                       x-model="band.up_to">

                <select class="col-span-5 rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500"
                        :name="'params[bands][' + i + '][calc_type]'"
                        x-model="band.calc_type">
                    @foreach ($bandCalcTypes as $value => $text)
                        <option value="{{ $value }}">{{ $text }}</option>
                    @endforeach
                </select>

                <input type="number" step="0.01" min="0" class="col-span-2 rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500"
                       :name="'params[bands][' + i + '][value]'"
                       x-model="band.value">

                {{-- Two bands are the minimum a table can have, so the last two rows keep
                     their rows rather than offering a button that would fail on use. --}}
                <button type="button" class="col-span-1 text-xs font-medium text-gray-400 hover:text-red-600 disabled:opacity-30"
                        x-on:click="remove(band.uid)" :disabled="bands.length <= 2">Remove</button>
            </div>
        </template>

        <button type="button" x-on:click="add()"
                class="mt-1 rounded-lg border border-brand-300 px-2.5 py-1 text-xs font-medium text-brand-800 hover:bg-brand-100">
            Add a band
        </button>
    </div>
</div>
