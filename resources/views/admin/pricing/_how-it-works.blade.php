{{--
    How a price is built, for whoever is configuring one.

    Collapsed by default: it answers a question somebody asks once and then knows, and a
    panel that lectures a daily user every morning gets scrolled past along with whatever
    sits beneath it.

    Expects: $audience — 'office' or 'agency'.
--}}
<details class="group rounded-xl border border-gray-200 bg-white shadow-sm">
    <summary class="flex cursor-pointer items-center justify-between px-6 py-4 text-base font-semibold text-brand-900 marker:content-['']">
        How a price is built
        <span class="text-xs font-normal text-gray-400 group-open:hidden">Show</span>
        <span class="hidden text-xs font-normal text-gray-400 group-open:inline">Hide</span>
    </summary>

    <div class="space-y-4 border-t border-gray-100 px-6 py-5 text-sm leading-relaxed text-gray-600">
        <p>
            A price is a <strong class="text-brand-900">ladder</strong>, not a lookup. It starts at the rate
            the supplier charges and each level adds its own rung on top. Nothing ever overwrites the
            supplier's number, and the supplier is always paid it.
        </p>

        {{-- Round numbers, and deliberately not read from configuration: this illustrates
             that the two percentages are BOTH of the 5,000, which is the decision it
             exists to explain rather than a figure that moves. --}}
        <div class="rounded-lg bg-gray-50 p-4 font-mono text-xs">
            <div class="flex justify-between border-b border-gray-200 pb-1.5 text-gray-500">
                <span>Supplier rate</span><span class="tabular-nums">5,000.00</span>
            </div>
            <div class="flex justify-between py-1.5">
                <span>+ Main Office, 10%</span><span class="tabular-nums">500.00</span>
            </div>
            <div class="flex justify-between pb-1.5">
                <span>+ {{ $audience === 'agency' ? 'You' : 'The agency' }}, 10%</span><span class="tabular-nums">500.00</span>
            </div>
            <div class="flex justify-between border-t border-gray-300 pt-1.5 font-semibold text-brand-900">
                <span>Customer pays</span><span class="tabular-nums">6,000.00</span>
            </div>
        </div>

        <dl class="space-y-3">
            <div>
                <dt class="font-semibold text-brand-900">Every rule that matches applies, and they add up.</dt>
                <dd>
                    A base percentage, a service fee and an international surcharge are three rules, and a
                    booking all three match pays all three. A rule is not a winner — it is a contributor.
                </dd>
            </div>

            <div>
                <dt class="font-semibold text-brand-900">Every percentage is of the supplier's rate.</dt>
                <dd>
                    In the ladder above both 10% rules take 500, because both are 10% of the 5,000 — not 10%
                    of what the level before them had already reached. Nothing compounds, and the order the
                    rules run in cannot change the total.
                    @if ($audience === 'agency')
                        Your own rung is worked out from that same supplier rate, so what you add does not
                        change when the Main Office changes what it adds.
                    @endif
                </dd>
            </div>

            <div>
                <dt class="font-semibold text-brand-900">A floor and a cap bound one rule.</dt>
                <dd>
                    <span class="font-mono text-xs">Floor</span> holds a contribution up, so "10%, but never
                    less than 500" is one rule. <span class="font-mono text-xs">Cap</span> holds it down, so
                    "10%, but never more than 3,000" is one rule too. Set both for a corridor. They bound what
                    <em>that rule</em> adds, not the price.
                </dd>
            </div>

            <div>
                <dt class="font-semibold text-brand-900">Rules can be narrowed.</dt>
                <dd>
                    Product, scope and supplier narrow a rule on their own. Beyond them, a travel-date window
                    makes a rule seasonal — measured against when the guest travels, not when the booking was
                    made — and the narrowing field takes named airlines, cabins, star ratings and cities. A
                    rule that matches nothing charges nothing, silently, so narrow deliberately.
                </dd>
            </div>

            <div>
                <dt class="font-semibold text-brand-900">The selling price is rounded once, at the end.</dt>
                <dd>
                    After every rung has contributed — never rung by rung, because rounding each one and then
                    adding them gives a total that does not match the rungs above it.
                </dd>
            </div>

            <div>
                <dt class="font-semibold text-brand-900">Nothing here can go negative.</dt>
                <dd>
                    A rule only ever adds. Discounts are not this mechanism, and a rule that would take
                    something away is refused rather than quietly applied.
                </dd>
            </div>
        </dl>

        <p class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
            <strong>Check before you trust it.</strong> The preview on this page runs the same engine that
            prices a real booking — not a copy of the arithmetic. What it shows is what would actually be
            charged.
        </p>
    </div>
</details>
