<?php

namespace App\Enums;

/**
 * What a booking is *for*.
 *
 * The `bookings` table is one spine carrying the parts every product shares —
 * reference, agency, environment, status, money, wallet linkage — with the
 * product-specific half in its own detail row. This is the discriminator that says
 * which half to look in.
 */
enum BookingProduct: string
{
    case Flight = 'flight';
    case Hotel = 'hotel';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * The supplier this product is bought from today.
     *
     * One-to-one for now and deliberately not a column-level assumption: the moment
     * a second hotel source exists, `bookings.supplier` is what distinguishes them
     * and this becomes a default rather than a fact.
     */
    public function defaultSupplier(): Supplier
    {
        return match ($this) {
            self::Flight => Supplier::TboAir,
            self::Hotel => Supplier::TboHotel,
        };
    }

    /**
     * What the supplier's own reference is called, for labelling it in the UI.
     */
    public function referenceLabel(): string
    {
        return match ($this) {
            self::Flight => 'PNR',
            self::Hotel => 'Confirmation no.',
        };
    }
}
