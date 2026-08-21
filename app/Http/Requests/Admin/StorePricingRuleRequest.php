<?php

namespace App\Http\Requests\Admin;

use App\Enums\BookingProduct;
use App\Enums\CalcType;
use App\Enums\PricingBasis;
use App\Enums\Supplier;
use App\Enums\TravelScope;
use App\Models\PricingRule;
use App\Services\Pricing\PricingContextFactory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validating a pricing rule.
 *
 * `calc_type` is restricted to the types that actually have a Calculator behind them.
 * A rule that cannot be computed is far better refused in the form that created it than
 * discovered on a live search, where it throws mid-quote.
 */
class StorePricingRuleRequest extends FormRequest
{
    /**
     * Where a rule sits in the list when nothing says otherwise.
     *
     * Neither form offers an order field. Every rule works from the supplier net, so
     * contributions never compound and the order they run in cannot change a total —
     * the column survives only to keep the listing deterministic, and rules that share
     * a value fall back to the order they were created in.
     */
    public const DEFAULT_PRIORITY = 100;

    public function authorize(): bool
    {
        return true; // route middleware and policy decide
    }

    /**
     * `basis` and `priority` are no longer asked for, so they are supplied here rather
     * than trusted to a hidden field — a form input is not enforcement.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'basis' => PricingBasis::Net->value,
            'priority' => $this->input('priority') ?: self::DEFAULT_PRIORITY,
        ]);

        // `none` has no amount to give, and `value` is NOT NULL. Supplying the zero here
        // rather than asking for it keeps the form from demanding a number whose only
        // legal answer is the one the type already implies.
        if ($this->input('calc_type') === CalcType::None->value) {
            $this->merge(['value' => 0]);
        }

        // The form posts matchers as JSON text, because the matchable set differs per
        // product and grows with each one — which is why the column is JSON too.
        // Unparseable input is left as the string it came in as, so the `array` rule
        // rejects it and messages() explains the shape rather than this method guessing.
        $raw = $this->input('matchers');

        if (is_string($raw)) {
            $trimmed = trim($raw);

            $this->merge([
                'matchers' => $trimmed === '' ? null : (json_decode($trimmed, true) ?? $raw),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Why the rule exists, in whoever added it's own words. Optional: requiring
            // it would only guarantee the word "test" ends up in the column.
            'description' => ['nullable', 'string', 'max:255'],

            'product' => ['required', Rule::in([...BookingProduct::values(), PricingRule::ANY])],
            'supplier' => ['nullable', Rule::in(array_keys(Supplier::options()))],
            'scope' => ['required', Rule::in([...TravelScope::values(), 'any'])],

            'calc_type' => ['required', Rule::in(array_keys(CalcType::options()))],
            'value' => ['required', 'numeric', 'min:0'],
            'basis' => ['required', Rule::in(array_keys(PricingBasis::options()))],
            'applies_to' => ['required', Rule::in(['total', 'base_fare', 'excl_ancillaries'])],

            'min_markup' => ['nullable', 'numeric', 'min:0'],
            'max_markup' => ['nullable', 'numeric', 'min:0', 'gte:min_markup'],
            'rounding' => ['required', Rule::in(['none', '1', '10', '50', '100'])],

            'priority' => ['required', 'integer', 'min:1', 'max:9999'],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'is_active' => ['boolean'],

            // Free-form narrowing — airline, cabin, isLcc, rating, city…
            'matchers' => ['nullable', 'array'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'value.min' => 'A markup cannot be negative. Discounts are a separate mechanism.',
            'max_markup.gte' => 'The cap must be at least the floor, or the rule can never be satisfied.',
            'matchers.array' => 'Narrowing must be written as JSON — {"airline": "PR"} for one value, '
                .'{"rating": [4, 5]} for any of several. Leave it empty to match everything.',
        ];
    }

    /**
     * A percentage over 100 is almost always a decimal in the wrong place — 15 meant as
     * 0.15, or 1500 meant as 15. It is not forbidden, but it is worth stopping to look at.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $type = $this->input('calc_type');
            $value = (float) $this->input('value');

            if ($type === CalcType::PercentageMarkup->value && $value > 100) {
                $validator->errors()->add('value', "{$value}% more than doubles the fare. Enter 15 for 15%.");
            }

            // The unit a fee scales on belongs to the product. A per-passenger fee on a
            // hotel grows the markup with a number the supplier never charged on — two
            // adults in one double room pay one room rate — and that was the live
            // system's bug. The form greys these out; this is what makes it binding.
            $product = (string) $this->input('product');
            $chosen = is_string($type) ? CalcType::tryFrom($type) : null;

            if ($chosen !== null && $product !== '' && ! $chosen->appliesToProduct($product)) {
                $validator->errors()->add(
                    'calc_type',
                    "{$chosen->label()} is {$chosen->productRestriction()}. "
                    .'Choose that product on this rule, or pick a type that means the same thing on all of them.'
                );
            }

            // A matcher key the context never emits reads as null, the comparison fails,
            // and the rule quietly never fires. A rule that charges nothing because of a
            // typo is indistinguishable from one nobody wrote, so the typo is caught here
            // rather than discovered in a margin report.
            $matchers = $this->input('matchers');

            if (is_array($matchers) && $product !== '') {
                $allowed = PricingContextFactory::matchableKeys($product);

                foreach (array_keys($matchers) as $key) {
                    if (in_array((string) $key, $allowed, true)) {
                        continue;
                    }

                    $validator->errors()->add(
                        'matchers',
                        "\"{$key}\" is not something this product carries, so the rule would never fire. "
                        .'Use one of: '.implode(', ', $allowed).'.'
                    );
                }
            }

            // A margin is a share of the selling price, so 100% of it needs a selling
            // price of infinity and anything beyond turns the sign around. Refused
            // rather than questioned: Money::margin() throws on these, and a rule that
            // throws mid-quote is exactly what validating calc types exists to prevent.
            if ($type === CalcType::PercentageMargin->value && $value >= 100) {
                $validator->errors()->add(
                    'value',
                    "A {$value}% margin cannot be reached — a margin is a share of the selling price, "
                    .'so it must be under 100%. For a fixed multiple of cost, use a percentage markup.'
                );
            }
        });
    }

    public function isActive(): bool
    {
        return (bool) $this->boolean('is_active');
    }
}
