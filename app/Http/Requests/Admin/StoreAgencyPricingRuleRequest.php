<?php

namespace App\Http\Requests\Admin;

/**
 * An agency's own rule.
 *
 * Identical to the Main Office form's validation, and deliberately so: **every rule at
 * every level works from the supplier net.** An agency adding 10% adds it to the
 * original supplier price, not to what the level above already charged it, so the levels
 * add rather than compound and the total does not depend on the order they run in.
 *
 * That is enforced in the parent, for both forms, rather than here for one — a rule that
 * compounds would make the order it runs in load-bearing, and neither screen asks for an
 * order any more.
 *
 * **The disclosure this accepts.** A percentage of net means an agency shown its own
 * markup of ₱500 on a 10% rule can divide back to a supplier net of ₱5,000. The leak is
 * arithmetic rather than a defect — the agency wrote the rule — so no redaction closes
 * it. Signed off on 15 August 2026 in favour of the simpler pricing model. What it makes
 * load-bearing is AgencyPriceView, which never puts a markup or a cost on a real offer at
 * all: the figure that inverts is only ever shown against a fare the agency typed itself.
 *
 * See D12 in _docs/pricing/01-architecture.md.
 *
 * The class survives as its own type because the controller and the policy pair on it,
 * and because an agency-only restriction is likely to return.
 */
class StoreAgencyPricingRuleRequest extends StorePricingRuleRequest
{
    //
}
