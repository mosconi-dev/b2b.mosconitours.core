{{--
    The ⓘ beside a price, and what it opens.

    One partial for every screen that shows a total — flight results, hotel rooms, and
    both booking wizards — so the breakdown reads the same wherever an agent meets it.

    Renders whichever shape the server sent. Someone entitled to the supplier net sees
    the whole ladder with each rung named by the agency that took it; an agency sees its
    own position, with everything above it fused into one opaque cost. That decision is
    `AgencyPriceView`'s, not this file's — see $fareBreakdown() in app.js.

    @param string $pricing  Alpine expression resolving to the `pricing` object,
                            e.g. 'offer.pricing', 'quote.pricing', 'room.pricing'.
    @param string $align    'right' (default) or 'left' — which edge the panel hangs from.
--}}
@php($align = $align ?? 'right')

<span class="relative inline-flex" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
    <template x-if="{{ $pricing }}">
        <button type="button" @click="open = ! open"
                :aria-expanded="open"
                aria-label="Show what makes up this fare"
                class="rounded-full p-0.5 text-gray-300 transition hover:bg-gray-100 hover:text-gray-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.852l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />
            </svg>
        </button>
    </template>

    <template x-if="open && $fareBreakdown({{ $pricing }})">
        <div x-transition.opacity x-data="{ b: $fareBreakdown({{ $pricing }}) }"
             class="absolute {{ $align === 'left' ? 'left-0' : 'right-0' }} top-full z-30 mt-2 w-72 cursor-default rounded-lg border border-gray-200 bg-white p-3 text-left shadow-lg">
            <p class="mb-2 text-[11px] font-semibold uppercase tracking-wide text-gray-500"
               x-text="b.entitled ? 'How this fare is built' : 'Your position on this fare'"></p>

            <div class="space-y-1 font-mono text-xs">
                <div class="flex justify-between gap-2">
                    <span class="text-gray-500" x-text="b.baseLabel"></span>
                    <span class="whitespace-nowrap tabular-nums text-brand-900" x-text="$money(b.baseAmount)"></span>
                </div>

                <template x-for="(row, i) in b.rows" :key="i">
                    <div>
                        <div class="flex justify-between gap-2">
                            <span class="text-gray-500" x-text="row.label"></span>
                            <span class="whitespace-nowrap tabular-nums text-gray-700">+ <span x-text="$money(row.amount)"></span></span>
                        </div>
                        <p x-show="row.note" x-cloak x-text="row.note"
                           class="text-[10px] leading-tight text-gray-400"></p>
                    </div>
                </template>

                <div x-show="! b.rows.length" x-cloak class="text-[11px] text-gray-400">
                    No markup on this fare — it sells at cost.
                </div>

                <div class="flex justify-between gap-2 border-t border-gray-200 pt-1">
                    <span class="text-gray-500" x-text="b.marginLabel"></span>
                    <span class="whitespace-nowrap tabular-nums text-gray-700" x-text="$money(b.marginAmount)"></span>
                </div>
                <div class="flex justify-between gap-2 font-semibold">
                    <span class="text-brand-900">Customer pays</span>
                    <span class="whitespace-nowrap tabular-nums text-brand-900" x-text="$money(b.totalAmount)"></span>
                </div>
            </div>
        </div>
    </template>
</span>
