<?php

namespace App\Services\Booking\Concerns;

use App\Models\Booking;
use App\Services\Pricing\PriceBreakdown;

/**
 * Writing a booking's price ladder down.
 *
 * Shared by the flight and hotel services because a booking must explain its price the
 * same way whichever was sold — and because two copies of this would be two places for
 * the rule snapshot to stop being written.
 *
 * Called inside the same transaction as the booking row and its wallet charge, so a
 * booking can never exist with a price nobody can account for.
 */
trait RecordsPriceLayers
{
    protected function recordPriceLayers(Booking $booking, PriceBreakdown $price): void
    {
        foreach ($price->layers as $layer) {
            $booking->priceLayers()->create($layer->toRow());
        }
    }
}
