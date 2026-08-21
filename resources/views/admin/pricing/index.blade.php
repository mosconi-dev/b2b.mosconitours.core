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

                        {{-- Product and Type are bound together: a per-passenger fee is
                             meaningless on a hotel and a per-room-night one on a flight,
                             so choosing a product narrows the types on offer. Alpine only
                             greys the wrong ones out — StorePricingRuleRequest is what
                             refuses them, so a form posted without JavaScript is still
                             held to the same rule. --}}
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3 lg:grid-cols-4"
                             x-data="{ product: @js(old('product', '*')), calcType: @js(old('calc_type', 'fixed')), byProduct: @js($calcTypesByProduct) }"
                             x-effect="if (! (calcType in byProduct[product])) calcType = 'fixed'">
                            <div>
                                <label for="product" class="mb-1 block text-xs font-medium text-gray-600">Product</label>
                                <select id="product" name="product" x-model="product" class="w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                                    @foreach ($products as $value => $text)
                                        <option value="{{ $value }}" @selected(old('product', '*') === (string) $value)>{{ $text }}</option>
                                    @endforeach
                                </select>
                            </div>

                            @foreach ([
                                ['scope', 'Scope', $scopes, 'any'],
                                ['supplier', 'Supplier', $suppliers, ''],
                            ] as [$field, $label, $options, $default])
                                <div>
                                    <label for="{{ $field }}" class="mb-1 block text-xs font-medium text-gray-600">{{ $label }}</label>
                                    <select id="{{ $field }}" name="{{ $field }}" class="w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                                        @foreach ($options as $value => $text)
                                            <option value="{{ $value }}" @selected(old($field, $default) === (string) $value)>{{ $text }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endforeach

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

                            @foreach ([
                                ['value', 'Amount or %', 'number', old('value'), 'required'],
                                ['min_markup', 'Floor', 'number', old('min_markup'), ''],
                                ['max_markup', 'Cap', 'number', old('max_markup'), ''],
                            ] as [$field, $label, $type, $value, $required])
                                <div>
                                    <label for="{{ $field }}" class="mb-1 block text-xs font-medium text-gray-600">{{ $label }}</label>
                                    <input id="{{ $field }}" name="{{ $field }}" type="{{ $type }}" step="0.01" value="{{ $value }}" {{ $required }}
                                           class="w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                                </div>
                            @endforeach

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
                            <input type="hidden" name="applies_to" value="total">
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
                        A domestic flight at supplier net
                        <span class="font-semibold text-brand-900">{{ number_format((float) config('pricing.preview_net', 5000), 2) }}</span>.
                    </p>
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
