<?php

namespace App\Services\Pricing;

/**
 * What a booker pays: the supplier's rate plus every level's markup.
 *
 * The other half of the type separation described on NetPrice. This is a terminal
 * type — it goes to a screen, a voucher or a database column, and never back into the
 * engine.
 */
final readonly class SellPrice
{
    public function __construct(public Money $amount) {}
}
