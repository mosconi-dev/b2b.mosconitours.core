{{--
    An agency's own markup — the rung it adds on top of the Main Office's.

    What is deliberately absent: the supplier net, and the Main Office's margin as a
    separate figure. This agency sees what it pays, what it adds, and what it sells for.
--}}
@php
    $options = \App\Http\Controllers\Admin\AgencyPricingController::formOptions();
    $editable = auth()->user()->can('markup.edit') && ! $isPricingRoot;
@endphp

<div class="space-y-6">
    @if ($isPricingRoot)
        {{-- The pricing root's own rules are the level everyone else builds on, so they
             live behind markup.office.* and its own screen. The policy refuses them
             here too, not just the link. --}}
        <div class="rounded-xl border border-indigo-200 bg-indigo-50 p-6">
            <h2 class="text-base font-semibold text-indigo-900">This agency is the pricing root</h2>
            <p class="mt-1 text-sm text-indigo-800">
                Its markup is inside every other agency's cost, so it is edited on the
                <strong>Markups</strong> screen rather than here.
            </p>
            @can('markup.office.view')
                <a href="{{ route('admin.pricing.index') }}"
                   class="mt-3 inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-3.5 py-2 text-sm font-semibold text-white transition hover:bg-indigo-700">
                    Open Markups
                </a>
            @endcan
        </div>
    @endif

    {{-- ------------------------------------------------------------- preview ---- --}}
    <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm"
         x-data="agencyMarkupPreview({ url: '{{ route('admin.agencies.markup.preview', $agency) }}' })">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="text-base font-semibold text-brand-900">What you sell for</h2>
                <p class="mt-1 text-sm text-gray-500">
                    Your cost, your markup, and the price your customer pays.
                </p>
            </div>

            @if ($editable)
                <form method="POST" action="{{ route('admin.agencies.markup.toggle', $agency) }}">
                    @csrf @method('PATCH')
                    <button type="submit" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50">
                        {{ $strategy->is_active ? 'Pause my markup' : 'Resume my markup' }}
                    </button>
                </form>
            @endif
        </div>

        @unless ($strategy->is_active)
            <p class="mt-4 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">
                Your markup is <strong>paused</strong>. You are currently selling at your cost.
            </p>
        @endunless

        <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
            <div>
                <label for="markup-cost" class="mb-1 block text-xs font-medium text-gray-600">A price you were quoted</label>
                <input id="markup-cost" x-model="form.net" type="number" step="0.01"
                       class="w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
            </div>
            <div>
                <label for="markup-product" class="mb-1 block text-xs font-medium text-gray-600">Product</label>
                <select id="markup-product" x-model="form.product" class="w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                    <option value="flight">Flight</option>
                    <option value="hotel">Hotel</option>
                </select>
            </div>
            <div>
                <label for="markup-scope" class="mb-1 block text-xs font-medium text-gray-600">Scope</label>
                <select id="markup-scope" x-model="form.scope" class="w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                    <option value="domestic">Domestic</option>
                    <option value="international">International</option>
                </select>
            </div>
        </div>

        <button type="button" @click="run()" :disabled="loading"
                class="mt-4 rounded-lg bg-brand-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-brand-800 disabled:opacity-50">
            <span x-text="loading ? 'Pricing…' : 'Preview'"></span>
        </button>

        <p x-show="error" x-cloak x-text="error" class="mt-3 text-sm font-medium text-red-700"></p>

        <div x-show="result" x-cloak class="mt-5 rounded-lg bg-gray-50 p-4 font-mono text-sm">
            <div class="flex justify-between py-1">
                <span class="text-gray-600">Your cost</span>
                <span class="tabular-nums text-brand-900" x-text="money(result.cost)"></span>
            </div>
            <div class="flex justify-between py-1">
                <span class="text-gray-600">Your markup</span>
                <span class="tabular-nums text-gray-700" x-text="money(result.markup)"></span>
            </div>
            <div class="mt-2 flex justify-between border-t border-gray-300 pt-2 font-semibold">
                <span class="text-brand-900">Selling price</span>
                <span class="tabular-nums text-brand-900" x-text="money(result.sell)"></span>
            </div>
        </div>
    </div>

    {{-- --------------------------------------------------------------- rules ---- --}}
    <div class="rounded-xl border border-gray-200 bg-white shadow-sm">
        <div class="border-b border-gray-100 px-6 py-4">
            <h2 class="text-base font-semibold text-brand-900">Your rules</h2>
            <p class="mt-1 text-sm text-gray-500">
                Evaluated top to bottom. <strong>The first rule that matches wins</strong> — put narrow
                rules above broad ones, and a catch-all last.
            </p>
        </div>

        @if ($strategy->rules->isEmpty())
            <p class="px-6 py-8 text-center text-sm text-gray-500">
                No rules yet, so you are selling at your cost and taking no margin.
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-6 py-3">Priority</th>
                            <th class="px-6 py-3">Applies to</th>
                            <th class="px-6 py-3">You add</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($strategy->rules as $rule)
                            <tr class="{{ $rule->is_active ? '' : 'opacity-50' }}">
                                <td class="px-6 py-3 font-mono text-xs text-gray-500">{{ $rule->priority }}</td>
                                <td class="px-6 py-3">
                                    <span class="font-medium text-brand-900">{{ $options['products'][$rule->product] ?? $rule->product }}</span>
                                    @if ($rule->scope !== 'any')
                                        <span class="ml-1 rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600">{{ $options['scopes'][$rule->scope] ?? $rule->scope }}</span>
                                    @endif
                                </td>
                                <td class="px-6 py-3 font-semibold text-brand-900">
                                    @if ($rule->calc_type->isPercentage())
                                        {{ rtrim(rtrim((string) $rule->value, '0'), '.') }}%
                                        <span class="ml-1 text-xs font-normal text-gray-400">of your cost</span>
                                    @else
                                        {{ number_format((float) $rule->value, 2) }}
                                    @endif
                                </td>
                                <td class="px-6 py-3 text-right">
                                    @if ($editable)
                                        <form method="POST" action="{{ route('admin.agencies.markup.rules.destroy', [$agency, $rule]) }}"
                                              onsubmit="return confirm('Remove this rule? Your selling prices change on the next search.')">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="text-xs font-medium text-red-600 hover:text-red-700">Remove</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($editable)
            <form method="POST" action="{{ route('admin.agencies.markup.rules.store', $agency) }}" class="border-t border-gray-100 bg-gray-50 px-6 py-5">
                @csrf
                <h3 class="mb-3 text-sm font-semibold text-brand-900">Add a rule</h3>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                    @foreach ([
                        ['product', 'Product', $options['products'], '*'],
                        ['scope', 'Scope', $options['scopes'], 'any'],
                        ['calc_type', 'Type', \App\Enums\CalcType::options(), 'fixed'],
                    ] as [$field, $label, $opts, $default])
                        <div>
                            <label for="ag-{{ $field }}" class="mb-1 block text-xs font-medium text-gray-600">{{ $label }}</label>
                            <select id="ag-{{ $field }}" name="{{ $field }}" class="w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                                @foreach ($opts as $value => $text)
                                    <option value="{{ $value }}" @selected(old($field, $default) === (string) $value)>{{ $text }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endforeach

                    <div>
                        <label for="ag-value" class="mb-1 block text-xs font-medium text-gray-600">Amount or %</label>
                        <input id="ag-value" name="value" type="number" step="0.01" value="{{ old('value') }}" required
                               class="w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                    </div>
                    <div>
                        <label for="ag-priority" class="mb-1 block text-xs font-medium text-gray-600">Priority</label>
                        <input id="ag-priority" name="priority" type="number" value="{{ old('priority', 100) }}" required
                               class="w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                    </div>

                    {{-- A percentage here is of YOUR cost, never of the supplier rate —
                         see StoreAgencyPricingRuleRequest. The server forces it either way. --}}
                    <input type="hidden" name="basis" value="running">
                    <input type="hidden" name="supplier" value="">
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
        @endif
    </div>

    {{-- -------------------------------------------------------------- margin ---- --}}
    <div class="rounded-xl border border-gray-200 bg-white shadow-sm">
        <div class="border-b border-gray-100 px-6 py-4">
            <h2 class="text-base font-semibold text-brand-900">What you earned</h2>
            <p class="mt-1 text-sm text-gray-500">
                Your own margin on bookings made here, taken from each booking's stored
                price rather than from today's rules.
            </p>
        </div>

        @if ($margin->isEmpty())
            <p class="px-6 py-8 text-center text-sm text-gray-500">No margin recorded yet.</p>
        @else
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-6 py-3">Month</th>
                        <th class="px-6 py-3 text-right">Bookings</th>
                        <th class="px-6 py-3 text-right">Margin</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($margin as $row)
                        <tr>
                            <td class="px-6 py-3 text-gray-600">{{ $row->period }}</td>
                            <td class="px-6 py-3 text-right tabular-nums text-gray-600">{{ $row->bookings }}</td>
                            <td class="px-6 py-3 text-right font-semibold tabular-nums text-brand-900">{{ $row->margin->formatted() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
