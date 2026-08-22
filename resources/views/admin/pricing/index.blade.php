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
                    {{-- Grouped by service line rather than listed flat: the question is
                         "what do we charge on domestic flights", and a line's rules only
                         make sense read together, because every one of them that matches
                         is charged. --}}
                    <div class="divide-y divide-gray-100">
                        @foreach ($serviceLines as $line)
                            <div class="px-6 py-4">
                                <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                                    <h3 class="text-sm font-semibold text-brand-900">
                                        {{ $line['label'] }}
                                        <span class="ml-1 text-xs font-normal text-gray-400">
                                            {{ $line['rules']->count() }} {{ Str::plural('rule', $line['rules']->count()) }}@if ($line['rules']->count() > 1), and a booking they all match pays every one @endif
                                        </span>
                                    </h3>

                                    @can('markup.office.edit')
                                        {{-- Prefills the add form below rather than opening
                                             another one: one form, one set of validation. --}}
                                        <a href="#add-rule" x-data
                                           x-on:click="$dispatch('pricing-line', @js(['product' => $line['product'], 'scope' => $line['scope']]))"
                                           class="rounded-lg border border-brand-300 px-2.5 py-1 text-xs font-medium text-brand-800 transition hover:bg-brand-50">
                                            Add rule to this line
                                        </a>
                                    @endcan
                                </div>

                                <div class="overflow-x-auto">
                                    <table class="min-w-full text-sm">
                                        <thead class="text-left text-xs font-semibold uppercase tracking-wide text-gray-400">
                                            <tr>
                                                <th class="py-2 pr-4 font-medium">Rule</th>
                                                <th class="py-2 pr-4 font-medium">Adds</th>
                                                <th class="py-2 pr-4 font-medium">Bounds</th>
                                                <th class="py-2"></th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100">
                                            @foreach ($line['rules'] as $rule)
                                                <tr class="{{ $rule->is_active ? '' : 'opacity-50' }}">
                                                    <td class="py-2.5 pr-4">
                                                        <span class="font-medium text-brand-900">
                                                            {{ filled($rule->description) ? $rule->description : $rule->calc_type->label() }}
                                                        </span>
                                                        @if (filled($rule->supplier))
                                                            <span class="ml-1 rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600">{{ $suppliers[$rule->supplier] ?? $rule->supplier }}</span>
                                                        @endif
                                                        @if ($rule->applies_to !== \App\Enums\AppliesTo::Total->value)
                                                            <span class="ml-1 rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600">{{ $appliesTo[$rule->applies_to] ?? $rule->applies_to }}</span>
                                                        @endif
                                                        @if ($rule->valid_from !== null || $rule->valid_to !== null)
                                                            <span class="ml-1 rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600">
                                                                {{ $rule->valid_from?->format('j M Y') ?? 'any date' }} – {{ $rule->valid_to?->format('j M Y') ?? 'onwards' }}
                                                            </span>
                                                        @endif
                                                        @if (filled($rule->matchers))
                                                            <span class="ml-1 rounded-full bg-gray-100 px-2 py-0.5 font-mono text-xs text-gray-600">{{ json_encode($rule->matchers) }}</span>
                                                        @endif
                                                        @unless ($rule->is_active)
                                                            <span class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">off</span>
                                                        @endunless
                                                    </td>
                                                    <td class="py-2.5 pr-4 font-semibold text-brand-900">{{ $rule->amountLabel() }}</td>
                                                    <td class="py-2.5 pr-4 text-xs text-gray-500">
                                                        @if ($rule->min_markup !== null || $rule->max_markup !== null)
                                                            min {{ $rule->min_markup === null ? '—' : number_format((float) $rule->min_markup, 2) }},
                                                            max {{ $rule->max_markup === null ? '—' : number_format((float) $rule->max_markup, 2) }}
                                                        @else
                                                            &mdash;
                                                        @endif
                                                    </td>
                                                    <td class="py-2.5 text-right whitespace-nowrap">
                                                        @can('markup.office.edit')
                                                            <a href="{{ route('admin.pricing.rules.edit', $rule) }}"
                                                               class="text-xs font-medium text-brand-700 hover:text-brand-900">Edit</a>
                                                            <form method="POST" action="{{ route('admin.pricing.rules.destroy', $rule) }}" class="ml-2 inline"
                                                                  onsubmit="return confirm('Remove this rule? Prices change on the next search.')">
                                                                @csrf @method('DELETE')
                                                                <button type="submit" class="text-xs font-medium text-red-600 hover:text-red-700">Remove</button>
                                                            </form>
                                                        @endcan
                                                    </td>
                                                </tr>

                                                {{-- A tier table is a rate sheet, so it is shown as one
                                                     rather than folded into "Tiered: 300.00 / 500.00". --}}
                                                @if ($rule->calc_type === \App\Enums\CalcType::Tiered)
                                                    @php $table = \App\Services\Pricing\TieredBands::fromParams($rule->params); @endphp
                                                    <tr class="{{ $rule->is_active ? '' : 'opacity-50' }}">
                                                        <td colspan="4" class="pb-3">
                                                            <table class="min-w-full rounded-lg bg-gray-50 text-xs">
                                                                <thead class="text-left uppercase tracking-wide text-gray-400">
                                                                    <tr>
                                                                        <th class="px-3 py-1.5 font-medium">Tier</th>
                                                                        <th class="px-3 py-1.5 font-medium">Min amount</th>
                                                                        <th class="px-3 py-1.5 font-medium">Max amount</th>
                                                                        <th class="px-3 py-1.5 font-medium">Charge</th>
                                                                    </tr>
                                                                </thead>
                                                                <tbody>
                                                                    @foreach ($table->grid() as $band)
                                                                        <tr class="border-t border-gray-200/70">
                                                                            <td class="px-3 py-1.5 text-gray-500">Class {{ $band['tier'] }}</td>
                                                                            <td class="px-3 py-1.5 tabular-nums text-gray-700">{{ $band['from']->formatted() }}</td>
                                                                            <td class="px-3 py-1.5 tabular-nums text-gray-700">{{ $band['to']?->formatted() ?? 'No limit' }}</td>
                                                                            <td class="px-3 py-1.5 font-medium text-brand-900">{{ $band['charge'] }}</td>
                                                                        </tr>
                                                                    @endforeach
                                                                </tbody>
                                                            </table>
                                                            <p class="mt-1 px-3 text-[11px] text-gray-400">
                                                                Read at {{ strtolower($table->unit()->label()) }},
                                                                {{ $table->mode() === \App\Enums\TierMode::Marginal ? 'each band charging only its own slice' : 'one band charging the whole amount' }}.
                                                            </p>
                                                        </td>
                                                    </tr>
                                                @endif
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                @can('markup.office.edit')
                    <form id="add-rule" method="POST" action="{{ route('admin.pricing.rules.store') }}" class="border-t border-gray-100 bg-gray-50 px-6 py-5">
                        @csrf
                        <h3 class="mb-3 text-sm font-semibold text-brand-900">Add a rule</h3>

                        @include('admin.pricing._field-guide', ['audience' => 'office'])

                        @include('admin.pricing._rule-fields', ['rule' => null])

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
