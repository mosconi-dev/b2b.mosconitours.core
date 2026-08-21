<?php

namespace App\Services\Pricing\Calculators;

use App\Enums\CalcType;
use App\Services\Pricing\Exceptions\PricingException;

/**
 * CalcType → Calculator.
 *
 * The engine asks this and calls what it gets back; it never branches on the type
 * itself. Registering a class here is the whole cost of adding a pricing strategy.
 *
 * An unregistered type throws rather than silently contributing zero. A rule that
 * cannot be computed is a configuration error, and a fare that is quietly ₱500 cheaper
 * than intended is the worst possible way to find out about one — validation refuses
 * these at the form, so reaching this exception means something bypassed it.
 */
class CalculatorRegistry
{
    /** @var array<string, Calculator> */
    private array $calculators = [];

    public function __construct()
    {
        $this->register(CalcType::Fixed, new FixedCalculator);
        $this->register(CalcType::PercentageMarkup, new PercentageMarkupCalculator);
    }

    public function register(CalcType $type, Calculator $calculator): void
    {
        $this->calculators[$type->value] = $calculator;
    }

    public function for(CalcType $type): Calculator
    {
        return $this->calculators[$type->value]
            ?? throw new PricingException("No calculator is registered for pricing type '{$type->value}'.");
    }

    public function has(CalcType $type): bool
    {
        return isset($this->calculators[$type->value]);
    }

    /**
     * @return array<int, CalcType>
     */
    public function registered(): array
    {
        return array_map(CalcType::from(...), array_keys($this->calculators));
    }
}
