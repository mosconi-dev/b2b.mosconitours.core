<?php

namespace App\Http\Requests\Admin;

use App\Enums\CalcType;
use App\Enums\PricingBasis;

/**
 * An agency's own rule, which is the Main Office rule form with one restriction.
 *
 * **A percentage rule at agency level must work from the running total, never net.**
 * The reason is arithmetic rather than policy: an agency may see its own markup, so if
 * it sets "10% of net" and is shown a markup of ₱500, the supplier net falls out as
 * ₱5,000 in one division. No amount of redaction closes that — the agency wrote the
 * rule. Working from the running total means the percentage is taken of the agency's
 * own cost, a figure it already knows, and it is also what "I add 10% to what I pay"
 * actually means.
 *
 * Fixed rules are unaffected: a flat ₱200 says nothing about what the room cost.
 *
 * See D12 in _docs/pricing/01-architecture.md. Reversible in one rule if the business
 * would rather accept the disclosure.
 */
class StoreAgencyPricingRuleRequest extends StorePricingRuleRequest
{
    /**
     * A percentage rule is forced onto the running basis rather than refused: the
     * agency asked for a percentage and there is exactly one basis it may have, so
     * rejecting the form would be asking them to guess the answer.
     */
    protected function prepareForValidation(): void
    {
        if ($this->input('calc_type') === CalcType::PercentageMarkup->value) {
            $this->merge(['basis' => PricingBasis::Running->value]);
        }
    }

    public function withValidator($validator): void
    {
        parent::withValidator($validator);

        $validator->after(function ($validator): void {
            if ($this->input('calc_type') !== CalcType::PercentageMarkup->value) {
                return;
            }

            if ($this->input('basis') !== PricingBasis::Running->value) {
                $validator->errors()->add(
                    'basis',
                    'An agency percentage is taken of your own cost, not of the supplier rate.',
                );
            }
        });
    }
}
