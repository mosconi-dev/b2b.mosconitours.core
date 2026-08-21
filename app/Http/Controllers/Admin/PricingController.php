<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BookingProduct;
use App\Enums\CalcType;
use App\Enums\PricingBasis;
use App\Enums\Supplier;
use App\Enums\TravelScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePricingRuleRequest;
use App\Models\Agency;
use App\Models\PricingRule;
use App\Services\Pricing\Exceptions\PricingException;
use App\Services\Pricing\NetPrice;
use App\Services\Pricing\PricingAdminService;
use App\Services\Pricing\PricingContext;
use App\Services\Pricing\PricingEngine;
use App\Services\Pricing\StrategyResolver;
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

        return view('admin.pricing.index', [
            'configured' => $configured,
            'mainOffice' => $mainOffice,
            'strategy' => $strategy?->load('rules'),
            'agencies' => Agency::active()->orderBy('name')->get(['id', 'name', 'code', 'type']),
            // Every agency's ladder at a glance, so an agency contributing nothing is
            // visible here rather than discovered in a margin report.
            'ladder' => $configured ? $this->ladder($mainOffice) : collect(),
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
        $data = $request->validate([
            'agency_id' => ['required', 'exists:agencies,id'],
            'net' => ['required', 'numeric', 'min:0'],
            'product' => ['required', 'in:flight,hotel'],
            'scope' => ['required', 'in:domestic,international'],
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
     * Every agency's effective markup on a sample fare, for the hierarchy view.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function ladder(Agency $mainOffice): Collection
    {
        $engine = app(PricingEngine::class);
        $sample = NetPrice::of(config('pricing.preview_net', 5000));

        return Agency::active()->orderBy('name')->get()->map(function (Agency $agency) use ($engine, $sample, $mainOffice): array {
            $breakdown = $engine->quote(
                new PricingContext(
                    product: BookingProduct::Flight,
                    supplier: Supplier::TboAir,
                    scope: TravelScope::Domestic,
                    net: $sample,
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
        return [
            'calcTypes' => CalcType::options(),
            // Every product's allowed types, so the form can narrow the select without a
            // round trip. Advisory here; StorePricingRuleRequest is what binds it.
            'calcTypesByProduct' => CalcType::optionsByProduct(),
            'bases' => PricingBasis::options(),
            'products' => [PricingRule::ANY => 'All products'] + array_reduce(
                BookingProduct::cases(),
                fn (array $c, BookingProduct $p): array => $c + [$p->value => $p->label()],
                [],
            ),
            'suppliers' => ['' => 'Any supplier'] + Supplier::options(),
            'scopes' => ['any' => 'Domestic and international'] + array_reduce(
                TravelScope::cases(),
                fn (array $c, TravelScope $s): array => $c + [$s->value => $s->label()],
                [],
            ),
        ];
    }
}
