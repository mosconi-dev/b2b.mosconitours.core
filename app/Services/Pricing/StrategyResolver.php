<?php

namespace App\Services\Pricing;

use App\Models\Agency;
use App\Models\PricingStrategy;
use App\Services\Pricing\Exceptions\PricingException;
use App\Services\Settings\Settings;
use Illuminate\Support\Facades\Cache;

/**
 * Who contributes to a price, and in what order.
 *
 * Two levels: the Main Office always, then the booker's own agency. There is no tree
 * walk — `agencies.parent_id` is nullable, is documented as reporting-only, and is
 * very likely unset on most rows, so a walk would silently skip the Main Office for
 * exactly those agencies. On the one rule that must always apply, that is not a risk
 * worth taking, so level 0 is prepended unconditionally.
 *
 * ADDING A LEVEL LATER CHANGES ONLY THIS METHOD. The engine loops whatever list comes
 * back and has no idea how long it is.
 */
class StrategyResolver
{
    /** Where the pricing root is named. Not inferred from AgencyType — see below. */
    public const MAIN_OFFICE_SETTING = 'pricing.main_office_agency_id';

    /**
     * Strategies already loaded during this request, by agency id.
     *
     * **Deliberately not the shared cache.** `config/cache.php` sets
     * `serializable_classes => false`, so the store unserializes NO classes: an Eloquent
     * model put into it comes back as `__PHP_Incomplete_Class` and the engine dies on
     * the type hint. That setting is a gadget-chain defence and is not worth weakening
     * to save one query, so nothing but this in-request memo stands between the engine
     * and the database.
     *
     * The repeat queries this exists to stop were always within a single request anyway
     * — a hotel city search prices a hundred properties, each of which resolves the same
     * two levels.
     *
     * @var array<int|string, PricingStrategy|null>
     */
    private array $loaded = [];

    /**
     * Bumped by forget(). An instance whose generation is behind drops what it holds.
     *
     * The memo is per-instance so it cannot outlive a request, but a resolver that has
     * already answered once must still notice a rule edited after it did — a queue
     * worker, or an admin write followed by a quote in the same process.
     */
    private static int $generation = 0;

    private int $seenGeneration = 0;

    public function __construct(private readonly Settings $settings) {}

    /**
     * The ladder for a booker, root first.
     *
     * @return array<int, array{level: int, agency: Agency, strategy: ?PricingStrategy}>
     */
    public function chain(?Agency $booker): array
    {
        $mainOffice = $this->mainOffice();

        $chain = [$this->rung(0, $mainOffice)];

        // A Main Office user must not pay the Main Office's own markup. Without this
        // the chain is [Main Office, Main Office] and level 0's rule is applied twice —
        // and the UNIQUE (booking_id, level) guard would not catch it, because the two
        // rows carry different levels. They sell at the house price by definition.
        if ($booker !== null && $booker->getKey() !== $mainOffice->getKey()) {
            $chain[] = $this->rung(1, $booker);
        }

        return $chain;
    }

    /**
     * The agency that is the pricing root.
     *
     * Read from a setting rather than by querying `type = main_office`, because nothing
     * in the schema guarantees only one row carries that type and a resolver that picked
     * arbitrarily between two would be choosing the house margin at random.
     *
     * Throws when unset or dangling. It never guesses and never falls back to a type
     * query: pricing that silently resolves to nothing sells everything at cost, which
     * is indistinguishable from a deliberate zero-margin configuration.
     */
    public function mainOffice(): Agency
    {
        $id = $this->settings->get(self::MAIN_OFFICE_SETTING);

        if (blank($id)) {
            throw new PricingException(
                'No Main Office is configured for pricing. Set the pricing root in Administration → Markups '
                .'before any price can be quoted.'
            );
        }

        $agency = Agency::find($id);

        if ($agency === null) {
            throw new PricingException(
                "The configured Main Office (agency #{$id}) no longer exists. Set the pricing root in "
                .'Administration → Markups.'
            );
        }

        return $agency;
    }

    /** Whether a pricing root has been configured at all, for the admin screens. */
    public function isConfigured(): bool
    {
        try {
            $this->mainOffice();

            return true;
        } catch (PricingException) {
            return false;
        }
    }

    /**
     * One rung, with its strategy and rules eagerly loaded.
     *
     * Memoised for the rest of the request — see $loaded for why it is not the shared
     * cache. An edited rule therefore takes effect on the very next request rather than
     * when a TTL expires.
     *
     * @return array{level: int, agency: Agency, strategy: ?PricingStrategy}
     */
    private function rung(int $level, Agency $agency): array
    {
        $id = $agency->getKey();

        if ($this->seenGeneration !== self::$generation) {
            $this->loaded = [];
            $this->seenGeneration = self::$generation;
        }

        // array_key_exists, not isset: "this agency has no strategy" is a null we want
        // to remember, or every priced item re-runs the miss.
        if (! array_key_exists($id, $this->loaded)) {
            $this->loaded[$id] = PricingStrategy::with('activeRules')
                ->where('agency_id', $id)
                ->where('is_active', true)
                ->first();
        }

        return ['level' => $level, 'agency' => $agency, 'strategy' => $this->loaded[$id]];
    }

    public static function cacheKey(int|string $agencyId): string
    {
        return "pricing:strategy:{$agencyId}";
    }

    /**
     * Called whenever a strategy or one of its rules changes.
     *
     * Invalidates every in-memory memo in this process, and clears the shared-cache row
     * that the version which cached across requests used to write — so an installation
     * upgrading in place cannot serve a strategy out of a row this code never writes.
     */
    public static function forget(int|string $agencyId): void
    {
        self::$generation++;

        Cache::forget(self::cacheKey($agencyId));
    }
}
