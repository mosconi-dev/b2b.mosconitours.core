<?php

namespace App\Services\Pricing;

use App\Models\Agency;
use App\Models\PricingRule;
use App\Models\PricingStrategy;
use App\Services\Rbac\AuditLogger;
use App\Services\Settings\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Writing pricing configuration.
 *
 * Every write goes through here for one reason: the resolver caches a strategy with its
 * rules, and a rule edited without invalidating that cache is a price that silently does
 * not change. Keeping the invalidation beside the write is the only way it cannot be
 * forgotten — so nothing else in the application saves a PricingStrategy or a
 * PricingRule.
 *
 * Holds no authorization. Who may write is decided by permissions and policies, as
 * everywhere else.
 */
class PricingAdminService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly Settings $settings,
    ) {}

    /**
     * The agency's strategy, created empty on first use.
     *
     * An empty strategy contributes nothing, so creating one is not a pricing change —
     * it just gives the screen something to hang rules on.
     */
    public function strategyFor(Agency $agency): PricingStrategy
    {
        $strategy = PricingStrategy::firstOrCreate(
            ['agency_id' => $agency->getKey()],
            ['name' => "{$agency->name} pricing", 'is_active' => true],
        );

        if ($strategy->wasRecentlyCreated) {
            $this->audit->log('pricing.strategy_created', $strategy, ['agency' => $agency->name]);
            StrategyResolver::forget($agency->getKey());
        }

        return $strategy;
    }

    public function toggleStrategy(PricingStrategy $strategy): PricingStrategy
    {
        $strategy->is_active = ! $strategy->is_active;
        $strategy->save();

        $this->invalidate($strategy);
        $this->audit->log(
            $strategy->is_active ? 'pricing.strategy_activated' : 'pricing.strategy_paused',
            $strategy,
        );

        return $strategy;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function addRule(PricingStrategy $strategy, array $data): PricingRule
    {
        $rule = new PricingRule($this->normalise($data));
        $rule->pricing_strategy_id = $strategy->getKey();
        $rule->save();

        $this->invalidate($strategy);
        $this->audit->log('pricing.rule_created', $rule, $rule->snapshot());

        return $rule;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateRule(PricingRule $rule, array $data): PricingRule
    {
        $before = $rule->snapshot();

        $rule->fill($this->normalise($data));
        $rule->save(); // bumps `version` — see PricingRule::booted()

        $this->invalidate($rule->strategy);
        $this->audit->log('pricing.rule_updated', $rule, ['before' => $before, 'after' => $rule->snapshot()]);

        return $rule;
    }

    public function deleteRule(PricingRule $rule): void
    {
        $snapshot = $rule->snapshot();
        $strategy = $rule->strategy;

        $rule->delete();

        $this->invalidate($strategy);
        $this->audit->log('pricing.rule_deleted', $strategy, $snapshot);
    }

    /**
     * Name the agency that is the pricing root.
     *
     * Refuses an inactive agency: pricing that resolves through a deactivated root is a
     * ladder whose bottom rung nobody is watching.
     */
    public function setMainOffice(Agency $agency): void
    {
        if (! $agency->is_active) {
            throw new Exceptions\PricingException(
                "{$agency->name} is deactivated and cannot be the pricing root."
            );
        }

        $previous = $this->settings->get(StrategyResolver::MAIN_OFFICE_SETTING);

        $this->settings->set(StrategyResolver::MAIN_OFFICE_SETTING, (string) $agency->getKey());

        // Both the old and the new root's cached strategies are now reached differently.
        if (filled($previous)) {
            StrategyResolver::forget($previous);
        }

        StrategyResolver::forget($agency->getKey());

        $this->audit->log('pricing.main_office_set', $agency, [
            'previous_agency_id' => $previous,
            'agency' => $agency->name,
        ]);
    }

    /**
     * Empty strings from a form become nulls, so "no cap" is a null rather than a zero
     * cap that holds every markup to nothing.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalise(array $data): array
    {
        foreach (['supplier', 'min_markup', 'max_markup', 'valid_from', 'valid_to'] as $key) {
            if (array_key_exists($key, $data) && $data[$key] === '') {
                $data[$key] = null;
            }
        }

        if (array_key_exists('matchers', $data) && blank($data['matchers'])) {
            $data['matchers'] = null;
        }

        return $data;
    }

    private function invalidate(PricingStrategy $strategy): void
    {
        DB::afterCommit(fn () => StrategyResolver::forget($strategy->agency_id));
    }
}
