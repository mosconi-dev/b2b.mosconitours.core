{{--
    The ladder preview.

    Posts to the real engine rather than reimplementing the arithmetic in Alpine. A
    second copy of the sum would drift from the first, and this screen exists precisely
    so somebody can check a change before it reaches a booking — a preview that can be
    wrong is worse than no preview.
--}}
<div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm"
     x-data="ladderPreview({ url: '{{ route('admin.pricing.preview') }}' })">
    <h2 class="text-base font-semibold text-brand-900">Ladder preview</h2>
    <p class="mt-1 text-sm text-gray-500">
        What an agency would actually be charged, computed by the pricing engine itself.
    </p>

    <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3 lg:grid-cols-5">
        <div>
            <label for="preview-agency" class="mb-1 block text-xs font-medium text-gray-600">Agency</label>
            <select id="preview-agency" x-model="form.agency_id" class="w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                @foreach ($agencies as $agency)
                    <option value="{{ $agency->id }}">{{ $agency->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="preview-net" class="mb-1 block text-xs font-medium text-gray-600">Supplier net</label>
            <input id="preview-net" x-model="form.net" type="number" step="0.01"
                   class="w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
        </div>
        <div>
            <label for="preview-product" class="mb-1 block text-xs font-medium text-gray-600">Product</label>
            <select id="preview-product" x-model="form.product" class="w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                <option value="flight">Flight</option>
                <option value="hotel">Hotel</option>
            </select>
        </div>
        <div>
            <label for="preview-scope" class="mb-1 block text-xs font-medium text-gray-600">Scope</label>
            <select id="preview-scope" x-model="form.scope" class="w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                <option value="domestic">Domestic</option>
                <option value="international">International</option>
            </select>
        </div>

        {{-- The counts a per-unit rule multiplies by. Shown per product because a fare
             has no rooms and a room rate has no head count — which is the whole reason
             those two calculation types are gated by product in the first place. --}}
        <div x-show="form.product === 'flight'">
            <label for="preview-pax" class="mb-1 block text-xs font-medium text-gray-600">Passengers</label>
            <input id="preview-pax" x-model="form.pax" type="number" min="1" max="9" step="1"
                   class="w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
        </div>

        <div x-show="form.product === 'hotel'" x-cloak>
            <label for="preview-rooms" class="mb-1 block text-xs font-medium text-gray-600">Rooms</label>
            <input id="preview-rooms" x-model="form.rooms" type="number" min="1" max="6" step="1"
                   class="w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
        </div>

        <div x-show="form.product === 'hotel'" x-cloak>
            <label for="preview-nights" class="mb-1 block text-xs font-medium text-gray-600">Nights</label>
            <input id="preview-nights" x-model="form.nights" type="number" min="1" max="30" step="1"
                   class="w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
        </div>
    </div>

    <button type="button" @click="run()" :disabled="loading"
            class="mt-4 rounded-lg bg-brand-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-brand-800 disabled:opacity-50">
        <span x-text="loading ? 'Pricing…' : 'Preview'"></span>
    </button>

    <p x-show="error" x-cloak x-text="error" class="mt-3 text-sm font-medium text-red-700"></p>

    <div x-show="result" x-cloak class="mt-5 rounded-lg bg-gray-50 p-4 font-mono text-sm">
        <div class="flex justify-between border-b border-gray-200 pb-2">
            <span class="text-gray-600">Supplier net</span>
            <span class="tabular-nums text-brand-900" x-text="money(result.net)"></span>
        </div>

        {{-- Keyed on the rule, not the level. A level is CUMULATIVE — every matching
             rule contributes its own rung — so two office rules produce two rungs both
             carrying level 0, and Alpine drops one of them on a duplicate key. The pair
             is what `booking_price_layers` is unique on, for the same reason. --}}
        <template x-for="layer in result.layers" :key="layer.level + ':' + layer.ruleId">
            <div class="flex justify-between py-1.5">
                <span class="text-gray-600">
                    + <span x-text="layer.agencyName"></span>
                    <span class="text-xs text-gray-400" x-text="layer.label"></span>
                </span>
                <span class="tabular-nums text-gray-700" x-text="money(layer.markup)"></span>
            </div>
        </template>

        <template x-if="result.layers.length === 0">
            <p class="py-2 text-xs text-gray-500">No level adds anything — this sells at supplier net.</p>
        </template>

        <div class="mt-2 flex justify-between border-t border-gray-300 pt-2 font-semibold">
            <span class="text-brand-900">Selling price</span>
            <span class="tabular-nums text-brand-900" x-text="money(result.sell)"></span>
        </div>
        <div class="mt-1 flex justify-between text-xs text-gray-500">
            <span>Total markup</span>
            <span class="tabular-nums" x-text="money(result.markupTotal)"></span>
        </div>
        <div class="mt-1 flex justify-between text-xs text-gray-500" x-show="result.roundingDelta !== '0.00'">
            <span>Rounding</span>
            <span class="tabular-nums" x-text="money(result.roundingDelta)"></span>
        </div>
    </div>
</div>

{{-- ladderPreview is registered in resources/js/app.js, like every other Alpine
     component here — the layout has no script stack to push to. --}}
