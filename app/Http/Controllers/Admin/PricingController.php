<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AppliesTo;
use App\Enums\BookingProduct;
use App\Enums\CalcType;
use App\Enums\PricingBasis;
use App\Enums\Supplier;
use App\Enums\TierMode;
use App\Enums\TierUnit;
use App\Enums\TravelScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePricingRuleRequest;
use App\Models\Agency;
use App\Models\PricingRule;
use App\Services\Pricing\CalcTypeGuide;
use App\Services\Pricing\Exceptions\PricingException;
use App\Services\Pricing\NetPrice;
use App\Services\Pricing\PricingAdminService;
use App\Services\Pricing\PricingContext;
use App\Services\Pricing\PricingContextFactory;
use App\Services\Pricing\PricingEngine;
use App\Services\Pricing\StrategyResolver;
use App\Services\Pricing\TieredBand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * The Main Office's pricing screens.
 *
 * Gated by `markup.office.*` throughout — this controller edits the level every other
 * agency's price is built on top of.
 */
class PricingController extends Controller
{
    public function __construct(
        private readonly PricingAdminService $admin,
        private readonly StrategyResolver $resolver,
    ) {}

    public function index(Request $request): View
    {
        $configured = $this->resolver->isConfigured();
        $mainOffice = $configured ? $this->resolver->mainOffice() : null;
        $strategy = $mainOffice ? $this->admin->strategyFor($mainOffice) : null;
        $sample = $this->sample($request);

        return view('admin.pricing.index', [
            'configured' => $configured,
            'mainOffice' => $mainOffice,
            'strategy' => $strategy?->load('rules'),
            'agencies' => Agency::active()->orderBy('name')->get(['id', 'name', 'code', 'type']),
            // Every agency's ladder at a glance, so an agency contributing nothing is
            // visible here rather than discovered in a margin report.
            'ladder' => $configured ? $this->ladder($mainOffice, $sample) : collect(),
            'sample' => $sample,
            ...$this->formOptions(),
        ]);
    }

    /**
     * Name the pricing root. Nothing can be quoted until this is set — the resolver
     * throws rather than guessing.
     */
    public function setMainOffice(Request $request): RedirectResponse
    {
        $data = $request->validate(['agency_id' => ['required', 'exists:agencies,id']]);

        try {
            $this->admin->setMainOffice(Agency::findOrFail($data['agency_id']));
        } catch (PricingException $e) {
            return back()->withErrors(['agency_id' => $e->getMessage()]);
        }

        return back()->with('status', 'Pricing root set. The Main Office strategy applies to every booking.');
    }

    public function storeRule(StorePricingRuleRequest $request): RedirectResponse
    {
        $strategy = $this->admin->strategyFor($this->resolver->mainOffice());

        $this->admin->addRule($strategy, $request->validated());

        return back()->with('status', 'Rule added. It applies to the next search.');
    }

    public function updateRule(StorePricingRuleRequest $request, PricingRule $rule): RedirectResponse
    {
        $this->admin->updateRule($rule, $request->validated());

        return back()->with('status', 'Rule updated.');
    }

    public function destroyRule(PricingRule $rule): RedirectResponse
    {
        $this->admin->deleteRule($rule);

        return back()->with('status', 'Rule removed.');
    }

    public function toggleStrategy(): RedirectResponse
    {
        $strategy = $this->admin->strategyFor($this->resolver->mainOffice());
        $this->admin->toggleStrategy($strategy);

        return back()->with('status', $strategy->is_active
            ? 'Main Office pricing is live again.'
            : 'Main Office pricing is paused — every booking sells at supplier net.');
    }

    /**
     * The ladder preview.
     *
     * Runs the REAL engine, not a second copy of the arithmetic. That is the whole point
     * of the engine being a pure function of context and configuration: what this shows
     * is what a booking would actually be charged, rather than an estimate that can
     * drift from it.
     */
    public function preview(Request $request, PricingEngine $engine): JsonResponse
    {
        // The counts are nullable and default to one, so a caller that predates them —
        // or a flight preview, which has no rooms — still prices. Their ceilings are the
        // search forms' own: nine passengers, six rooms.
        $data = $request->validate([
            'agency_id' => ['required', 'exists:agencies,id'],
            'net' => ['required', 'numeric', 'min:0'],
            'product' => ['required', 'in:flight,hotel'],
            'scope' => ['required', 'in:domestic,international'],
            'pax' => ['nullable', 'integer', 'min:1', 'max:9'],
            'rooms' => ['nullable', 'integer', 'min:1', 'max:6'],
            'nights' => ['nullable', 'integer', 'min:1', 'max:30'],
        ]);

        $agency = Agency::findOrFail($data['agency_id']);
        $product = BookingProduct::from($data['product']);

        try {
            $breakdown = $engine->quote(
                new PricingContext(
                    product: $product,
                    supplier: $product->defaultSupplier(),
                    scope: TravelScope::from($data['scope']),
                    net: NetPrice::of($data['net']),
                    // A per-passenger or per-room-night rule multiplies by these, so a
                    // preview that always sent one could never show what such a rule
                    // actually charges — which is the only question it is asked.
                    paxCount: (int) ($data['pax'] ?? 1),
                    roomCount: (int) ($data['rooms'] ?? 1),
                    nights: (int) ($data['nights'] ?? 1),
                ),
                $agency,
            );
        } catch (PricingException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // The full ladder: this screen is `markup.office.*` gated, so its viewer is
        // entitled to every rung.
        return response()->json($breakdown->toArray() + ['agency' => $agency->name]);
    }

    /**
     * The fare every agency in the hierarchy view is priced against.
     *
     * **Clamped, not validated.** A `validate()` here would redirect back to this same
     * page on a bad query string, which is this same page with the same bad query
     * string — a loop. Out-of-range input silently becomes the nearest sensible value
     * instead, which is the right answer for a display control nobody can break.
     *
     * @return array{net: string, product: BookingProduct, scope: TravelScope, pax: int, rooms: int, nights: int}
     */
    private function sample(Request $request): array
    {
        $net = $request->query('sample_net');

        return [
            'net' => is_numeric($net) && (float) $net >= 0
                ? (string) $net
                : (string) config('pricing.preview_net', 5000),
            'product' => BookingProduct::tryFrom((string) $request->query('sample_product')) ?? BookingProduct::Flight,
            'scope' => TravelScope::tryFrom((string) $request->query('sample_scope')) ?? TravelScope::Domestic,
            'pax' => $this->count($request->query('sample_pax'), 9),
            'rooms' => $this->count($request->query('sample_rooms'), 6),
            'nights' => $this->count($request->query('sample_nights'), 30),
        ];
    }

    /** A count from the query string, held between one and the search forms' own ceiling. */
    private function count(mixed $value, int $max): int
    {
        return max(1, min($max, (int) $value ?: 1));
    }

    /**
     * Every agency's effective markup on a sample fare, for the hierarchy view.
     *
     * One context, priced against every agency — so the column is a comparison between
     * partners rather than a quote. The counts matter for exactly the reason they matter
     * in the preview: a per-passenger or per-room-night rule charges a multiple, and a
     * table fixed at one of each reads those rules low.
     *
     * @param  array{net: string, product: BookingProduct, scope: TravelScope, pax: int, rooms: int, nights: int}  $sample
     * @return Collection<int, array<string, mixed>>
     */
    private function ladder(Agency $mainOffice, array $sample): Collection
    {
        $engine = app(PricingEngine::class);
        $net = NetPrice::of($sample['net']);

        return Agency::active()->orderBy('name')->get()->map(function (Agency $agency) use ($engine, $net, $sample, $mainOffice): array {
            $breakdown = $engine->quote(
                new PricingContext(
                    product: $sample['product'],
                    supplier: $sample['product']->defaultSupplier(),
                    scope: $sample['scope'],
                    net: $net,
                    paxCount: $sample['pax'],
                    roomCount: $sample['rooms'],
                    nights: $sample['nights'],
                ),
                $agency,
            );

            return [
                'agency' => $agency,
                'isRoot' => $agency->getKey() === $mainOffice->getKey(),
                'cost' => $breakdown->cost(),
                'sell' => $breakdown->sell->amount,
                'markup' => $breakdown->markupTotal(),
                'ownMargin' => $breakdown->ownMargin(),
                'hasStrategy' => $breakdown->layerAt(1) !== null || $agency->getKey() === $mainOffice->getKey(),
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        $anySupplier = ['' => 'Any supplier'];

        return [
            'calcTypes' => CalcType::options(),
            // Every product's allowed types, so the form can narrow the select without a
            // round trip. Advisory here; StorePricingRuleRequest is what binds it.
            'calcTypesByProduct' => CalcType::optionsByProduct(),
            // Worked examples, computed by the real calculators. See CalcTypeGuide.
            'calcTypeGuide' => app(CalcTypeGuide::class)->examples(),
            // What a rule may narrow on, per product. The factory owns this list because
            // it is the only class that knows what a product's context carries.
            'matchableKeys' => PricingContextFactory::matchableKeysByProduct(),
            // A tier table's own vocabulary: the modes it can be charged in, and the
            // subset of types a band may use (context-free ones only — see TieredBand).
            'tierModes' => TierMode::options(),
            'tierUnits' => TierUnit::options(),
            'tierUnitsByProduct' => TierUnit::optionsByProduct(),
            // A flight tier table means a per-TICKET table to the people who write one,
            // so the form starts there and follows the product until somebody chooses.
            'tierUnitDefaults' => TierUnit::defaultsByProduct(),
            'bandCalcTypes' => array_reduce(
                TieredBand::allowedTypes(),
                fn (array $c, CalcType $t): array => $c + [$t->value => $t->label()],
                [],
            ),
            'appliesTo' => AppliesTo::options(),
            'appliesToByProduct' => AppliesTo::optionsByProduct(),
            'bases' => PricingBasis::options(),
            'products' => [PricingRule::ANY => 'All products'] + array_reduce(
                BookingProduct::cases(),
                fn (array $c, BookingProduct $p): array => $c + [$p->value => $p->label()],
                [],
            ),
            'suppliers' => $anySupplier + Supplier::options(),
            // Which supplier each product can actually be bought from. A flight rule
            // narrowed to TBO Hotel matches nothing, ever; the select greys it out and
            // StorePricingRuleRequest refuses it. The wildcard survives on every
            // product — "any supplier" is never the wrong answer.
            'suppliersByProduct' => array_map(
                fn (array $options): array => $anySupplier + $options,
                Supplier::optionsByProduct(),
            ),
            'scopes' => ['any' => 'Domestic and international'] + array_reduce(
                TravelScope::cases(),
                fn (array $c, TravelScope $s): array => $c + [$s->value => $s->label()],
                [],
            ),
        ];
    }
}
