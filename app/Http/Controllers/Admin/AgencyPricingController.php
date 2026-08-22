<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AppliesTo;
use App\Enums\BookingProduct;
use App\Enums\CalcType;
use App\Enums\Supplier;
use App\Enums\TierMode;
use App\Enums\TierUnit;
use App\Enums\TravelScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAgencyPricingRuleRequest;
use App\Models\Agency;
use App\Models\PricingRule;
use App\Services\Pricing\AgencyPriceView;
use App\Services\Pricing\CalcTypeGuide;
use App\Services\Pricing\Exceptions\PricingException;
use App\Services\Pricing\Money;
use App\Services\Pricing\NetPrice;
use App\Services\Pricing\PricingAdminService;
use App\Services\Pricing\PricingContext;
use App\Services\Pricing\PricingContextFactory;
use App\Services\Pricing\PricingEngine;
use App\Services\Pricing\TieredBand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * An agency's own markup — the rung it adds on top of the Main Office's.
 *
 * Lives under the agency hub rather than on its own page, so an agency reaches its
 * pricing where it already reaches its wallet, its users and its roles. Gated by
 * `markup.*` and scoped by PricingStrategyPolicy, which is what stops `markup.edit` in
 * one agency doing anything in another.
 */
class AgencyPricingController extends Controller
{
    public function __construct(
        private readonly PricingAdminService $admin,
        private readonly PricingEngine $engine,
    ) {}

    public function storeRule(StoreAgencyPricingRuleRequest $request, Agency $agency): RedirectResponse
    {
        $strategy = $this->admin->strategyFor($agency);

        Gate::authorize('update', $strategy);

        $this->admin->addRule($strategy, $request->validated());

        return back()->with('status', 'Markup rule added. It applies to your next search.');
    }

    public function updateRule(StoreAgencyPricingRuleRequest $request, Agency $agency, PricingRule $rule): RedirectResponse
    {
        // Ownership first: authorizing against another agency's strategy answers 403,
        // which confirms the rule exists. Whether it does is not this agency's business.
        $this->guardBelongs($rule, $agency);
        Gate::authorize('update', $rule->strategy);

        $this->admin->updateRule($rule, $request->validated());

        return back()->with('status', 'Markup rule updated.');
    }

    public function destroyRule(Agency $agency, PricingRule $rule): RedirectResponse
    {
        // Ownership first: authorizing against another agency's strategy answers 403,
        // which confirms the rule exists. Whether it does is not this agency's business.
        $this->guardBelongs($rule, $agency);
        Gate::authorize('update', $rule->strategy);

        $this->admin->deleteRule($rule);

        return back()->with('status', 'Markup rule removed.');
    }

    public function toggle(Agency $agency): RedirectResponse
    {
        $strategy = $this->admin->strategyFor($agency);

        Gate::authorize('update', $strategy);

        $this->admin->toggleStrategy($strategy);

        return back()->with('status', $strategy->is_active
            ? 'Your markup is live again.'
            : 'Your markup is paused — you are selling at your cost.');
    }

    /**
     * The agency's own preview: cost, their markup, selling price.
     *
     * Deliberately reuses the same engine as everything else, then hands the result
     * through AgencyPriceView — so what this endpoint can return is bounded by the same
     * rule that bounds a search result, not by what this controller remembers to omit.
     */
    public function preview(Request $request, Agency $agency, PricingEngine $engine): JsonResponse
    {
        $strategy = $this->admin->strategyFor($agency);

        Gate::authorize('view', $strategy);

        // Nullable and defaulted to one — see PricingController::preview() for why the
        // counts are here at all, and where the ceilings come from.
        $data = $request->validate([
            'net' => ['required', 'numeric', 'min:0'],
            'product' => ['required', 'in:flight,hotel'],
            'scope' => ['required', 'in:domestic,international'],
            'pax' => ['nullable', 'integer', 'min:1', 'max:9'],
            'rooms' => ['nullable', 'integer', 'min:1', 'max:6'],
            'nights' => ['nullable', 'integer', 'min:1', 'max:30'],
        ]);

        $product = BookingProduct::from($data['product']);

        try {
            $breakdown = $engine->quote(
                new PricingContext(
                    product: $product,
                    supplier: $product->defaultSupplier(),
                    scope: TravelScope::from($data['scope']),
                    // The figure typed here is a NET one for the Main Office and a COST
                    // one for an agency, which is why only the office screen labels it
                    // "supplier net". Either way the engine is given the supplier's
                    // number and the viewer filter decides what comes back.
                    net: NetPrice::of($data['net']),
                    paxCount: (int) ($data['pax'] ?? 1),
                    roomCount: (int) ($data['rooms'] ?? 1),
                    nights: (int) ($data['nights'] ?? 1),
                ),
                $agency,
            );
        } catch (PricingException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // The agency's own rung against the figure they typed. forOwnLadder() drops the
        // Main Office rung the engine also computed: showing `cost` from the full chain
        // would give up the Main Office markup as the difference from the input.
        return response()->json(
            AgencyPriceView::forOwnLadder($breakdown, $request->user(), Money::of($data['net']))->toArray(),
        );
    }

    /**
     * A rule reached through the wrong agency's URL is a 404, not a 403: whether that
     * rule exists is not this agency's business.
     */
    private function guardBelongs(PricingRule $rule, Agency $agency): void
    {
        abort_unless((int) $rule->strategy->agency_id === (int) $agency->getKey(), 404);
    }

    /**
     * @return array<string, mixed>
     */
    public static function formOptions(): array
    {
        return [
            'calcTypes' => CalcType::options(),
            // Every product's allowed types, so the form can narrow the select without a
            // round trip. Advisory here; StoreAgencyPricingRuleRequest is what binds it.
            'calcTypesByProduct' => CalcType::optionsByProduct(),
            'calcTypeGuide' => app(CalcTypeGuide::class)->examples(),
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
