<?php

namespace App\Http\Requests\Admin;

use App\Enums\BookingProduct;
use App\Enums\CalcType;
use App\Enums\PricingBasis;
use App\Enums\Supplier;
use App\Enums\TravelScope;
use App\Models\PricingRule;
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
        });
    }

    public function isActive(): bool
    {
        return (bool) $this->boolean('is_active');
    }
}
