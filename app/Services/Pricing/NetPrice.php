<?php

namespace App\Services\Pricing;

/**
 * A supplier's rate, before anything of ours is added.
 *
 * Half of the type separation that stops a marked-up price being marked up again.
 * PricingContext accepts one of these and nothing else, and there is deliberately no
 * constructor, cast or helper anywhere that turns a SellPrice back into a NetPrice — so
 * feeding a priced figure into the engine is a type error at the call site rather than
 * an inflated fare in production.
 *
 * If you find yourself wanting to build one of these from a SellPrice, the answer is to
 * re-read the supplier's price (FareQuote, PreBook), which is what both booking services
 * already do.
 */
final readonly class NetPrice
{
    public function __construct(public Money $amount) {}

    public static function of(string|float|int $amount): self
    {
        return new self(Money::of($amount));
    }
}
