{{--
    Every field a pricing rule has, for both forms that write one.

    Shared so that adding a rule and editing one cannot drift apart — a field offered on
    one screen and not the other is how a rule ends up carrying a value nobody can change.

    $rule is the rule being edited, or null when adding. Every default reads
    old('x', $rule?->x ?? default), so a validation failure repopulates from the post and
    a fresh edit repopulates from the row.

    $audience is 'office' or 'agency'. The only field that differs is Supplier: the office
    may narrow a rule to one source, an agency's rules are its own margin on whatever it
    sold and the field is pinned empty. Everything else is deliberately identical — the
    two screens drifted once already, and floor and cap went missing from one of them for
    months because of it.

    $idPrefix keeps the ids unique when a screen carries this and a rules table at once.

    The hidden basis/rounding/is_active trio is deliberate — see the note beside it.

    Expects: $rule, $audience, $idPrefix, and the option arrays from formOptions().
--}}
    {{-- Product and Type are bound together: a per-passenger fee is
         meaningless on a hotel and a per-room-night one on a flight,
         so choosing a product narrows the types on offer. Alpine only
         greys the wrong ones out — StorePricingRuleRequest is what
         refuses them, so a form posted without JavaScript is still
         held to the same rule. --}}
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3 lg:grid-cols-4"
         x-data="{
             product: @js(old('product', $rule?->product ?? '*')),
             scope: @js(old('scope', $rule?->scope ?? 'any')),
             calcType: @js(old('calc_type', $rule?->calc_type->value ?? 'fixed')),
             chargedOn: @js(old('applies_to', $rule?->applies_to ?? 'total')),
             supplier: @js(old('supplier', $rule?->supplier ?? '')),
             byProduct: @js($calcTypesByProduct),
             chargedOnByProduct: @js($appliesToByProduct),
             supplierByProduct: @js($suppliersByProduct),
             matchable: @js($matchableKeys),
             get hasAmount() { return this.calcType !== 'tiered' && this.calcType !== 'none' },
         }"
         {{-- A service line's "Add rule" button sends its product and scope here rather
              than opening a second form: one form, one set of validation, and no second
              copy of any of this to keep in step. Harmless on the edit page, where
              nothing ever dispatches it. --}}
         x-on:pricing-line.window="product = $event.detail.product; scope = $event.detail.scope"
         x-effect="
             if (! (calcType in byProduct[product])) calcType = 'fixed';
             if (! (chargedOn in chargedOnByProduct[product])) chargedOn = 'total';
             if (! (supplier in supplierByProduct[product])) supplier = '';
         ">
        <div>
            <label for="{{ $idPrefix }}product" class="mb-1 block text-xs font-medium text-gray-600">Product</label>
            <select id="{{ $idPrefix }}product" name="product" x-model="product" class="w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                @foreach ($products as $value => $text)
                    <option value="{{ $value }}" @selected(old('product', $rule?->product ?? '*') === (string) $value)>{{ $text }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="{{ $idPrefix }}scope" class="mb-1 block text-xs font-medium text-gray-600">Scope</label>
            <select id="{{ $idPrefix }}scope" name="scope" x-model="scope" class="w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                @foreach ($scopes as $value => $text)
                    <option value="{{ $value }}" @selected(old('scope', $rule?->scope ?? 'any') === (string) $value)>{{ $text }}</option>
                @endforeach
            </select>
        </div>

        {{-- Gated by product like Type, and for a blunter reason: a
             flight is only ever bought from TBO Air, so a flight rule
             narrowed to TBO Hotel matches no booking that will ever
             exist. It would save, sit in the list looking live, and
             charge nothing. Any supplier stays offered throughout.

             An agency never chooses one: its rules are its own margin on
             whatever it sold, so the field is pinned empty below. --}}
        @if ($audience === 'office')
            <div>
                <label for="{{ $idPrefix }}supplier" class="mb-1 block text-xs font-medium text-gray-600">Supplier</label>
                <select id="{{ $idPrefix }}supplier" name="supplier" x-model="supplier" class="w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                    @foreach ($suppliers as $value => $text)
                        @php $supplierRestriction = filled($value) ? \App\Enums\Supplier::from($value)->productRestriction() : null; @endphp
                        <option value="{{ $value }}"
                                :disabled="! (@js($value) in supplierByProduct[product])"
                                @selected(old('supplier', $rule?->supplier ?? '') === (string) $value)>{{ $text }}@if ($supplierRestriction) — {{ $supplierRestriction }}@endif</option>
                    @endforeach
                </select>
            </div>
        @else
            <input type="hidden" name="supplier" value="">
        @endif

        <div>
            <label for="{{ $idPrefix }}calc_type" class="mb-1 block text-xs font-medium text-gray-600">Type</label>
            <select id="{{ $idPrefix }}calc_type" name="calc_type" x-model="calcType" class="w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                @foreach ($calcTypes as $value => $text)
                    @php $restriction = \App\Enums\CalcType::from($value)->productRestriction(); @endphp
                    <option value="{{ $value }}"
                            :disabled="! (@js($value) in byProduct[product])"
                            @selected(old('calc_type', $rule?->calc_type->value ?? 'fixed') === (string) $value)>{{ $text }}@if ($restriction) — {{ $restriction }}@endif</option>
                @endforeach
            </select>
        </div>

        @include('admin.pricing._calc-type-help', ['span' => 'sm:col-span-2 lg:col-span-4', 'rule' => $rule])

        @include('admin.pricing._tier-bands', ['span' => 'sm:col-span-3 lg:col-span-4', 'rule' => $rule])

        {{-- Two types have no amount to give: `none` takes nothing and
             `tiered` keeps its numbers in its bands. Asking for one
             anyway leaves a required box whose only legal answer is
             the one the type already implies. --}}
        <div x-show="hasAmount" @if (in_array(old('calc_type', $rule?->calc_type->value ?? 'fixed'), ['tiered', 'none'], true)) x-cloak @endif>
            <label for="{{ $idPrefix }}value" class="mb-1 block text-xs font-medium text-gray-600">Amount or %</label>
            <input id="{{ $idPrefix }}value" name="value" type="number" step="0.01" value="{{ old('value', $rule === null ? '' : (float) $rule->value) }}"
                   :required="hasAmount"
                   class="w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
        </div>

        @foreach ([
            ['min_markup', 'Floor', old('min_markup', $rule?->min_markup)],
            ['max_markup', 'Cap', old('max_markup', $rule?->max_markup)],
        ] as [$field, $label, $value])
            <div>
                <label for="{{ $idPrefix }}{{ $field }}" class="mb-1 block text-xs font-medium text-gray-600">{{ $label }}</label>
                <input id="{{ $idPrefix }}{{ $field }}" name="{{ $field }}" type="number" step="0.01" value="{{ $value }}"
                       class="w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
            </div>
        @endforeach

        {{-- Gated by product exactly as Type is, and for the same
             reason: only a flight arrives with its fare and its tax
             apart. The request refuses the combination too. --}}
        <div>
            <label for="{{ $idPrefix }}applies_to" class="mb-1 block text-xs font-medium text-gray-600">Charged on</label>
            <select id="{{ $idPrefix }}applies_to" name="applies_to" x-model="chargedOn" class="w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                @foreach ($appliesTo as $value => $text)
                    @php $chargedOnRestriction = \App\Enums\AppliesTo::from($value)->productRestriction(); @endphp
                    <option value="{{ $value }}"
                            :disabled="! (@js($value) in chargedOnByProduct[product])"
                            @selected(old('applies_to', $rule?->applies_to ?? 'total') === (string) $value)>{{ $text }}@if ($chargedOnRestriction) — {{ $chargedOnRestriction }}@endif</option>
                @endforeach
            </select>
        </div>

        {{-- Measured against the TRAVEL date when the context knows
             one: a December peak-season rule is about when the guest
             flies, not when the agent happened to book. --}}
        @foreach ([
            ['valid_from', 'Travelling from', 'optional'],
            ['valid_to', 'Travelling until', 'optional'],
        ] as [$field, $label, $hint])
            <div>
                <label for="{{ $idPrefix }}{{ $field }}" class="mb-1 block text-xs font-medium text-gray-600">
                    {{ $label }} <span class="font-normal text-gray-400">— {{ $hint }}</span>
                </label>
                <input id="{{ $idPrefix }}{{ $field }}" name="{{ $field }}" type="date" value="{{ old($field, $rule?->{$field}?->format('Y-m-d')) }}"
                       class="w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
            </div>
        @endforeach

        <div class="sm:col-span-2 lg:col-span-2">
            <label for="{{ $idPrefix }}matchers" class="mb-1 block text-xs font-medium text-gray-600">
                Narrow it further <span class="font-normal text-gray-400">— optional, JSON</span>
            </label>
            <input id="{{ $idPrefix }}matchers" name="matchers" type="text" value="{{ old('matchers', $rule?->matchers === null ? '' : json_encode($rule->matchers)) }}"
                   placeholder='{"airline": ["PR", "5J"]}'
                   class="w-full rounded-lg border-gray-300 font-mono text-sm focus:border-brand-500 focus:ring-brand-500">
            <p class="mt-1 text-xs text-gray-400">
                A list means any of them. This product carries:
                <span class="font-mono" x-text="matchable[product].join(', ')"></span>
            </p>
        </div>

        <div class="sm:col-span-2 lg:col-span-4">
            <label for="{{ $idPrefix }}description" class="mb-1 block text-xs font-medium text-gray-600">
                Note <span class="font-normal text-gray-400">— optional, why this rule exists</span>
            </label>
            <input id="{{ $idPrefix }}description" name="description" type="text" maxlength="255"
                   value="{{ old('description', $rule?->description) }}"
                   placeholder="{{ $audience === 'office' ? 'e.g. Covers the card fee on international issues' : 'e.g. Peak season surcharge agreed with the office' }}"
                   class="w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
        </div>

        {{-- Every rule works from the supplier net, so contributions
             never compound and the order they run in cannot change a
             total. That is why there is no Basis choice and no Order
             field: both only ever mattered to a compounding rule.
             The engine still honours `running` if a rule carries it,
             so re-offering it is a form change and nothing more. --}}
        <input type="hidden" name="basis" value="net">
        <input type="hidden" name="rounding" value="none">
        {{-- Editing a paused rule must not quietly restart it, and there is no toggle
             on the screen to say otherwise. --}}
        <input type="hidden" name="is_active" value="{{ old('is_active', $rule?->is_active ?? true) ? 1 : 0 }}">
    </div>
