<x-app-layout>
    <x-slot name="header">
        <x-page-heading title="Markups" subtitle="Main Office pricing — the level every agency's price is built on.">
            <x-slot name="icon">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 6h.008v.008H6V6z" />
                </svg>
            </x-slot>
        </x-page-heading>
    </x-slot>

    <div class="max-w-5xl space-y-6">
        <x-admin.flash />

        {{-- Nothing can be quoted until a pricing root is named. The resolver throws
             rather than guessing, so this is a blocking state, not a suggestion. --}}
        @unless ($configured)
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-6">
                <h2 class="text-base font-semibold text-amber-900">No pricing root is configured</h2>
                <p class="mt-1 text-sm text-amber-800">
                    Pricing cannot run until one agency is named as the Main Office. Until then every
                    search sells at supplier net.
                </p>

                @can('markup.office.edit')
                    <form method="POST" action="{{ route('admin.pricing.main-office') }}" class="mt-4 flex flex-wrap items-end gap-3">
                        @csrf @method('PUT')
                        <div>
                            <label for="agency_id" class="mb-1 block text-xs font-medium text-amber-900">Main Office</label>
                            <select id="agency_id" name="agency_id" class="rounded-lg border-amber-300 text-sm focus:border-amber-500 focus:ring-amber-500">
                                @foreach ($agencies as $agency)
                                    <option value="{{ $agency->id }}" @selected($agency->type === \App\Enums\AgencyType::MainOffice)>
                                        {{ $agency->label() }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <button type="submit" class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-amber-700">
                            Set pricing root
                        </button>
                    </form>
                    @error('agency_id')
                        <p class="mt-2 text-sm font-medium text-red-700">{{ $message }}</p>
                    @enderror
                @endcan
            </div>
        @else
            {{-- ---------------------------------------------------------- root ---- --}}
            <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h2 class="text-base font-semibold text-brand-900">Pricing root</h2>
                        <p class="mt-1 text-sm text-gray-500">
                            <span class="font-semibold text-brand-900">{{ $mainOffice->label() }}</span> is the Main Office.
                            Its markup is inside every agency's cost, and its own members are not charged it.
                        </p>
                    </div>

                    @can('markup.office.edit')
                        <form method="POST" action="{{ route('admin.pricing.toggle') }}">
                            @csrf @method('PATCH')
                            <button type="submit" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50">
                                {{ $strategy->is_active ? 'Pause pricing' : 'Resume pricing' }}
                            </button>
                        </form>
                    @endcan
                </div>

                @unless ($strategy->is_active)
                    <p class="mt-4 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">
                        Main Office pricing is <strong>paused</strong>. Every booking currently sells at supplier net.
                    </p>
                @endunless
            </div>

            {{-- ------------------------------------------------- how it works ---- --}}
            @include('admin.pricing._how-it-works', ['audience' => 'office'])

            {{-- ---------------------------------------------------------- rules ---- --}}
            <div class="rounded-xl border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-100 px-6 py-4">
                    <h2 class="text-base font-semibold text-brand-900">Rules</h2>
                    <p class="mt-1 text-sm text-gray-500">
                        <strong>Every rule that matches applies, and they add up.</strong> A base rate, a
                        service fee and a surcharge are three rules, and a booking they all match pays
                        all three &mdash; so a rule left switched on keeps charging.
                        <br>
                        <span class="text-gray-400">Every rule is a percentage or an amount of the
                        <strong class="font-medium text-gray-500">supplier net</strong>, so they never
                        compound on each other and the order they are listed in does not affect the
                        total.</span>
                    </p>
                </div>

                @if ($strategy->rules->isEmpty())
                    <p class="px-6 py-8 text-center text-sm text-gray-500">
                        No rules yet, so the Main Office adds nothing and every booking sells at supplier net.
                    </p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                <tr>
                                    <th class="px-6 py-3">Applies to</th>
                                    <th class="px-6 py-3">Adds</th>
                                    <th class="px-6 py-3">Bounds</th>
                                    <th class="px-6 py-3"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($strategy->rules as $rule)
                                    <tr class="{{ $rule->is_active ? '' : 'opacity-50' }}">
                                        <td class="px-6 py-3">
                                            <span class="font-medium text-brand-900">{{ $products[$rule->product] ?? $rule->product }}</span>
                                            @if ($rule->scope !== 'any')
                                                <span class="ml-1 rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600">{{ $scopes[$rule->scope] ?? $rule->scope }}</span>
                                            @endif
                                            @if (filled($rule->supplier))
                                                <span class="ml-1 rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600">{{ $suppliers[$rule->supplier] ?? $rule->supplier }}</span>
                                            @endif
                                            @if (filled($rule->description))
                                                <p class="mt-0.5 text-xs text-gray-500">{{ $rule->description }}</p>
                                            @endif
                                        </td>
                                        <td class="px-6 py-3 font-semibold text-brand-900">
                                            {{ $rule->amountLabel() }}
                                        </td>
                                        <td class="px-6 py-3 text-xs text-gray-500">
                                            @if ($rule->min_markup !== null || $rule->max_markup !== null)
                                                min {{ $rule->min_markup === null ? '—' : number_format((float) $rule->min_markup, 2) }},
                                                max {{ $rule->max_markup === null ? '—' : number_format((float) $rule->max_markup, 2) }}
                                            @else
                                                &mdash;
                                            @endif
                                        </td>
                                        <td class="px-6 py-3 text-right">
                                            @can('markup.office.edit')
                                                <form method="POST" action="{{ route('admin.pricing.rules.destroy', $rule) }}"
                                                      onsubmit="return confirm('Remove this rule? Prices change on the next search.')">
                                                    @csrf @method('DELETE')
                                                    <button type="submit" class="text-xs font-medium text-red-600 hover:text-red-700">Remove</button>
                                                </form>
                                            @endcan
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                @can('markup.office.edit')
                    <form method="POST" action="{{ route('admin.pricing.rules.store') }}" class="border-t border-gray-100 bg-gray-50 px-6 py-5">
                        @csrf
                        <h3 class="mb-3 text-sm font-semibold text-brand-900">Add a rule</h3>

                        @include('admin.pricing._field-guide', ['audience' => 'office'])

                        {{-- Product and Type are bound together: a per-passenger fee is
                             meaningless on a hotel and a per-room-night one on a flight,
                             so choosing a product narrows the types on offer. Alpine only
                             greys the wrong ones out — StorePricingRuleRequest is what
                             refuses them, so a form posted without JavaScript is still
                             held to the same rule. --}}
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3 lg:grid-cols-4"
                             x-data="{
                                 product: @js(old('product', '*')),
                                 calcType: @js(old('calc_type', 'fixed')),
                                 chargedOn: @js(old('applies_to', 'total')),
                                 supplier: @js(old('supplier', '')),
                                 byProduct: @js($calcTypesByProduct),
                                 chargedOnByProduct: @js($appliesToByProduct),
                                 supplierByProduct: @js($suppliersByProduct),
                                 matchable: @js($matchableKeys),
                                 get hasAmount() { return this.calcType !== 'tiered' && this.calcType !== 'none' },
                             }"
                             x-effect="
                                 if (! (calcType in byProduct[product])) calcType = 'fixed';
                                 if (! (chargedOn in chargedOnByProduct[product])) chargedOn = 'total';
                                 if (! (supplier in supplierByProduct[product])) supplier = '';
                             ">
                            <div>
                                <label for="product" class="mb-1 block text-xs font-medium text-gray-600">Product</label>
                                <select id="product" name="product" x-model="product" class="w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                                    @foreach ($products as $value => $text)
                                        <option value="{{ $value }}" @selected(old('product', '*') === (string) $value)>{{ $text }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div>
                                <label for="scope" class="mb-1 block text-xs font-medium text-gray-600">Scope</label>
                                <select id="scope" name="scope" class="w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                                    @foreach ($scopes as $value => $text)
                                        <option value="{{ $value }}" @selected(old('scope', 'any') === (string) $value)>{{ $text }}</option>
                                    @endforeach
                                </select>
                            </div>

                            {{-- Gated by product like Type, and for a blunter reason: a
                                 flight is only ever bought from TBO Air, so a flight rule
                                 narrowed to TBO Hotel matches no booking that will ever
                                 exist. It would save, sit in the list looking live, and
                                 charge nothing. Any supplier stays offered throughout. --}}
                            <div>
                                <label for="supplier" class="mb-1 block text-xs font-medium text-gray-600">Supplier</label>
                                <select id="supplier" name="supplier" x-model="supplier" class="w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                                    @foreach ($suppliers as $value => $text)
                                        @php $supplierRestriction = filled($value) ? \App\Enums\Supplier::from($value)->productRestriction() : null; @endphp
                                        <option value="{{ $value }}"
                                                :disabled="! (@js($value) in supplierByProduct[product])"
                                                @selected(old('supplier', '') === (string) $value)>{{ $text }}@if ($supplierRestriction) — {{ $supplierRestriction }}@endif</option>
                                    @endforeach
                                </select>
                            </div>

                            <div>
                                <label for="calc_type" class="mb-1 block text-xs font-medium text-gray-600">Type</label>
                                <select id="calc_type" name="calc_type" x-model="calcType" class="w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                                    @foreach ($calcTypes as $value => $text)
                                        @php $restriction = \App\Enums\CalcType::from($value)->productRestriction(); @endphp
                                        <option value="{{ $value }}"
                                                :disabled="! (@js($value) in byProduct[product])"
                                                @selected(old('calc_type', 'fixed') === (string) $value)>{{ $text }}@if ($restriction) — {{ $restriction }}@endif</option>
                                    @endforeach
                                </select>
                            </div>

                            @include('admin.pricing._calc-type-help', ['span' => 'sm:col-span-2 lg:col-span-4'])

                            @include('admin.pricing._tier-bands', ['span' => 'sm:col-span-3 lg:col-span-4'])

                            {{-- Two types have no amount to give: `none` takes nothing and
                                 `tiered` keeps its numbers in its bands. Asking for one
                                 anyway leaves a required box whose only legal answer is
                                 the one the type already implies. --}}
                            <div x-show="hasAmount" @if (in_array(old('calc_type', 'fixed'), ['tiered', 'none'], true)) x-cloak @endif>
                                <label for="value" class="mb-1 block text-xs font-medium text-gray-600">Amount or %</label>
                                <input id="value" name="value" type="number" step="0.01" value="{{ old('value') }}"
                                       :required="hasAmount"
                                       class="w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                            </div>

                            @foreach ([
                                ['min_markup', 'Floor', old('min_markup')],
                                ['max_markup', 'Cap', old('max_markup')],
                            ] as [$field, $label, $value])
                                <div>
                                    <label for="{{ $field }}" class="mb-1 block text-xs font-medium text-gray-600">{{ $label }}</label>
                                    <input id="{{ $field }}" name="{{ $field }}" type="number" step="0.01" value="{{ $value }}"
                                           class="w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                                </div>
                            @endforeach

                            {{-- Gated by product exactly as Type is, and for the same
                                 reason: only a flight arrives with its fare and its tax
                                 apart. The request refuses the combination too. --}}
                            <div>
                                <label for="applies_to" class="mb-1 block text-xs font-medium text-gray-600">Charged on</label>
                                <select id="applies_to" name="applies_to" x-model="chargedOn" class="w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                                    @foreach ($appliesTo as $value => $text)
                                        @php $chargedOnRestriction = \App\Enums\AppliesTo::from($value)->productRestriction(); @endphp
                                        <option value="{{ $value }}"
                                                :disabled="! (@js($value) in chargedOnByProduct[product])"
                                                @selected(old('applies_to', 'total') === (string) $value)>{{ $text }}@if ($chargedOnRestriction) — {{ $chargedOnRestriction }}@endif</option>
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
                                    <label for="{{ $field }}" class="mb-1 block text-xs font-medium text-gray-600">
                                        {{ $label }} <span class="font-normal text-gray-400">— {{ $hint }}</span>
                                    </label>
                                    <input id="{{ $field }}" name="{{ $field }}" type="date" value="{{ old($field) }}"
                                           class="w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                                </div>
                            @endforeach

                            <div class="sm:col-span-2 lg:col-span-2">
                                <label for="matchers" class="mb-1 block text-xs font-medium text-gray-600">
                                    Narrow it further <span class="font-normal text-gray-400">— optional, JSON</span>
                                </label>
                                <input id="matchers" name="matchers" type="text" value="{{ old('matchers') }}"
                                       placeholder='{"airline": ["PR", "5J"]}'
                                       class="w-full rounded-lg border-gray-300 font-mono text-sm focus:border-brand-500 focus:ring-brand-500">
                                <p class="mt-1 text-xs text-gray-400">
                                    A list means any of them. This product carries:
                                    <span class="font-mono" x-text="matchable[product].join(', ')"></span>
                                </p>
                            </div>

                            <div class="sm:col-span-2 lg:col-span-4">
                                <label for="description" class="mb-1 block text-xs font-medium text-gray-600">
                                    Note <span class="font-normal text-gray-400">— optional, why this rule exists</span>
                                </label>
                                <input id="description" name="description" type="text" maxlength="255"
                                       value="{{ old('description') }}"
                                       placeholder="e.g. Covers the card fee on international issues"
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
                            <input type="hidden" name="is_active" value="1">
                        </div>

                        @if ($errors->any())
                            <ul class="mt-3 space-y-1 text-sm font-medium text-red-700">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        @endif

                        <button type="submit" class="mt-4 rounded-lg bg-brand-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-brand-800">
                            Add rule
                        </button>
                    </form>
                @endcan
            </div>

            {{-- -------------------------------------------------------- preview ---- --}}
            @include('admin.pricing._preview')

            {{-- ------------------------------------------------------ hierarchy ---- --}}
            <div class="rounded-xl border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-100 px-6 py-4">
                    <h2 class="text-base font-semibold text-brand-900">Every agency, on a sample fare</h2>
                    <p class="mt-1 text-sm text-gray-500">
                        @php
                            $unit = $sample['product'] === \App\Enums\BookingProduct::Hotel
                                ? trim(($sample['rooms'] > 1 ? $sample['rooms'].' rooms' : 'one room')
                                    .' for '.($sample['nights'] > 1 ? $sample['nights'].' nights' : 'one night'))
                                : ($sample['pax'] > 1 ? $sample['pax'].' passengers' : 'one passenger');
                        @endphp
                        {{ $sample['scope']->label() }} {{ strtolower($sample['product']->label()) }} at supplier net
                        <span class="font-semibold text-brand-900">{{ number_format((float) $sample['net'], 2) }}</span>,
                        {{ $unit }}. One fare, priced for everyone — a comparison between partners, not a quote.
                    </p>

                    {{-- A GET form, so the sample lives in the URL and can be handed to
                         somebody else. The controller CLAMPS what arrives rather than
                         validating it: a validation failure would redirect back to this
                         page, which is this page with the same query string. --}}
                    <form method="GET" action="{{ route('admin.pricing.index') }}"
                          class="mt-3 grid grid-cols-1 items-end gap-3 sm:grid-cols-3 lg:grid-cols-6"
                          x-data="{ product: @js($sample['product']->value) }">
                        <div>
                            <label for="sample_net" class="mb-1 block text-xs font-medium text-gray-600">Supplier net</label>
                            <input id="sample_net" name="sample_net" type="number" step="0.01" min="0" value="{{ $sample['net'] }}"
                                   class="w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                        </div>

                        <div>
                            <label for="sample_product" class="mb-1 block text-xs font-medium text-gray-600">Product</label>
                            <select id="sample_product" name="sample_product" x-model="product"
                                    class="w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                                @foreach (\App\Enums\BookingProduct::cases() as $case)
                                    <option value="{{ $case->value }}" @selected($sample['product'] === $case)>{{ $case->label() }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label for="sample_scope" class="mb-1 block text-xs font-medium text-gray-600">Scope</label>
                            <select id="sample_scope" name="sample_scope"
                                    class="w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                                @foreach (\App\Enums\TravelScope::cases() as $case)
                                    <option value="{{ $case->value }}" @selected($sample['scope'] === $case)>{{ $case->label() }}</option>
                                @endforeach
                            </select>
                        </div>

                        {{-- Per product, for the same reason the preview panel is: a fare
                             has no rooms and a room rate has no head count. --}}
                        <div x-show="product === 'flight'">
                            <label for="sample_pax" class="mb-1 block text-xs font-medium text-gray-600">Passengers</label>
                            <input id="sample_pax" name="sample_pax" type="number" min="1" max="9" step="1" value="{{ $sample['pax'] }}"
                                   class="w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                        </div>

                        <div x-show="product === 'hotel'" x-cloak>
                            <label for="sample_rooms" class="mb-1 block text-xs font-medium text-gray-600">Rooms</label>
                            <input id="sample_rooms" name="sample_rooms" type="number" min="1" max="6" step="1" value="{{ $sample['rooms'] }}"
                                   class="w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                        </div>

                        <div x-show="product === 'hotel'" x-cloak>
                            <label for="sample_nights" class="mb-1 block text-xs font-medium text-gray-600">Nights</label>
                            <input id="sample_nights" name="sample_nights" type="number" min="1" max="30" step="1" value="{{ $sample['nights'] }}"
                                   class="w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                        </div>

                        <div>
                            <button type="submit" class="w-full rounded-lg border border-brand-900 px-4 py-2 text-sm font-semibold text-brand-900 transition hover:bg-brand-900 hover:text-white">
                                Re-price
                            </button>
                        </div>
                    </form>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="px-6 py-3">Agency</th>
                                <th class="px-6 py-3 text-right">Their cost</th>
                                <th class="px-6 py-3 text-right">Their margin</th>
                                <th class="px-6 py-3 text-right">Selling price</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($ladder as $row)
                                <tr>
                                    <td class="px-6 py-3">
                                        <span class="font-medium text-brand-900">{{ $row['agency']->name }}</span>
                                        @if ($row['isRoot'])
                                            <span class="ml-1 rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700">Main Office</span>
                                        @elseif (! $row['hasStrategy'])
                                            {{-- Worth seeing here rather than discovering in a margin report. --}}
                                            <span class="ml-1 rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-500">no markup</span>
                                        @endif
                                    </td>
                                    <td class="px-6 py-3 text-right tabular-nums text-gray-600">{{ $row['cost']->formatted() }}</td>
                                    <td class="px-6 py-3 text-right tabular-nums text-gray-600">{{ $row['ownMargin']->formatted() }}</td>
                                    <td class="px-6 py-3 text-right font-semibold tabular-nums text-brand-900">{{ $row['sell']->formatted() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endunless
    </div>
</x-app-layout>
