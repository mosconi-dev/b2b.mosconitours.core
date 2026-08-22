{{--
    What each field on the rule form means.

    Sits inside the form, under its heading, rather than in the page-level explainer:
    these are answers to "what do I put here", asked with the cursor already in the box.
    Collapsed, because they are answers you need once.

    "Charged on" reads its definitions off the AppliesTo enum, so the only copy of that
    vocabulary is the one the select and the validator already use.

    Expects: $audience — 'office' or 'agency'.
--}}
<details class="group mb-4 rounded-lg border border-gray-200 bg-white">
    <summary class="flex cursor-pointer items-center justify-between px-4 py-2.5 text-xs font-semibold text-brand-900 marker:content-['']">
        What each field means
        <span class="font-normal text-gray-400 group-open:hidden">Show</span>
        <span class="hidden font-normal text-gray-400 group-open:inline">Hide</span>
    </summary>

    <dl class="space-y-3 border-t border-gray-100 px-4 py-3 text-xs leading-relaxed text-gray-600">
        <div>
            <dt class="font-semibold text-brand-900">Product</dt>
            <dd>
                What the rule prices. <em>All products</em> matches everything — but a rule charged per
                passenger, per room-night, or on the base fare has to name one, because those mean different
                things on a flight and on a hotel. Naming a product unlocks them.
            </dd>
        </div>

        <div>
            <dt class="font-semibold text-brand-900">Scope</dt>
            <dd>
                Domestic or international, worked out from where you sell. <em>Domestic and international</em>
                matches either.
            </dd>
        </div>

        @if ($audience === 'office')
            <div>
                <dt class="font-semibold text-brand-900">Supplier</dt>
                <dd>
                    Limits the rule to one supplier's inventory. <em>Any supplier</em> matches all of them,
                    and is almost always what you want: each product is bought from one source today, so
                    naming it narrows nothing. Choosing a product greys out the suppliers that product is
                    never bought from — a flight rule pinned to the hotel supplier would match no booking
                    at all.
                </dd>
            </div>
        @endif

        <div>
            <dt class="font-semibold text-brand-900">Type</dt>
            <dd>
                How the amount becomes a markup. Choose one and the panel below it explains what it does, with
                a worked example.
            </dd>
        </div>

        <div>
            <dt class="font-semibold text-brand-900">Amount or %</dt>
            <dd>
                The number the type works with — pesos for a flat or per-unit fee, a percentage for the two
                percentage types. It can never be negative: a rule only ever adds.
            </dd>
        </div>

        <div>
            <dt class="font-semibold text-brand-900">Floor</dt>
            <dd>
                The <strong>least</strong> this rule may add. "10%, but never less than 500" is a single rule:
                <span class="font-mono">10</span> in Amount, <span class="font-mono">500</span> in Floor. It
                is what stops a percentage collapsing to nothing on a cheap fare. Leave it empty for no floor.
            </dd>
        </div>

        <div>
            <dt class="font-semibold text-brand-900">Cap</dt>
            <dd>
                The <strong>most</strong> this rule may add — "10%, but never more than 3,000". Set Floor and
                Cap together for a corridor. Both bound what <em>this rule</em> contributes, not the final
                price: other rules still add on top of it.
            </dd>
        </div>

        <div>
            <dt class="font-semibold text-brand-900">Charged on</dt>
            <dd>
                Which part of the supplier's rate a percentage is taken of.
                <ul class="mt-1 space-y-1">
                    @foreach (\App\Enums\AppliesTo::cases() as $case)
                        <li>
                            <span class="font-medium text-gray-700">{{ $case->label() }}</span>@if ($case->productRestriction())
                                <span class="ml-1 rounded-full bg-gray-100 px-1.5 py-0.5 text-[10px] font-medium text-gray-500">{{ $case->productRestriction() }}</span>
                            @endif
                            — {{ $case->guidance() }}
                        </li>
                    @endforeach
                </ul>
            </dd>
        </div>

        <div>
            <dt class="font-semibold text-brand-900">Travelling from / Travelling until</dt>
            <dd>
                Makes the rule seasonal. Both are measured against <strong>when the guest travels</strong>, not
                when the booking was made — a December peak-season rule is about the trip, not the purchase.
                Fill one for an open-ended window; leave both empty and the rule always applies.
            </dd>
        </div>

        <div>
            <dt class="font-semibold text-brand-900">Narrow it further</dt>
            <dd>
                Extra conditions, written as JSON. <span class="font-mono">{"airline": "PR"}</span> matches one
                value; <span class="font-mono">{"airline": ["PR", "5J"]}</span> matches any of several. The
                keys have to be ones the chosen product actually carries — they are listed under the box, and
                anything else is refused, because a condition nothing can satisfy makes a rule that charges
                nothing and says nothing.
            </dd>
        </div>

        <div>
            <dt class="font-semibold text-brand-900">Note</dt>
            <dd>
                Why the rule exists, in your own words. It is copied onto every booking the rule prices, so a
                price can still account for itself long after the rule has been changed or removed.
            </dd>
        </div>
    </dl>
</details>
