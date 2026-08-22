<?php

namespace App\Enums;

use Illuminate\Support\Str;

/**
 * The external inventory sources we transact with.
 *
 * TBO Air and TBO Hotel share a vendor and nothing else: one authenticates with a
 * session token, the other with Basic Auth on every call; one reports success in
 * `ResponseStatus`, the other in `Status.Code`. They are two suppliers that happen
 * to be sold by one company, and everything keyed here treats them that way.
 */
enum Supplier: string
{
    case TboAir = 'tboair';
    case TboHotel = 'tbohotel';

    public function label(): string
    {
        return match ($this) {
            self::TboAir => 'TBO Air',
            self::TboHotel => 'TBO Hotel',
        };
    }

    /**
     * The config file this supplier's credentials and endpoints live in.
     */
    public function configKey(): string
    {
        return $this->value;
    }

    /**
     * Where the platform-wide test/live choice is stored.
     *
     * Air's key is `tbo.environment` rather than `tboair.environment` because it was
     * named before there was a second supplier, and the rows already exist.
     */
    public function settingKey(): string
    {
        return match ($this) {
            self::TboAir => 'tbo.environment',
            self::TboHotel => 'tbohotel.environment',
        };
    }

    /**
     * The RBAC module governing this supplier. Air's is `supplier.tbo`, for the same
     * historical reason as the setting key.
     */
    public function module(): string
    {
        return match ($this) {
            self::TboAir => 'supplier.tbo',
            self::TboHotel => 'supplier.tbohotel',
        };
    }

    /**
     * Holding this permission is what lets a user's own override reach `live`.
     */
    public function livePermission(): string
    {
        return $this->module().'.live';
    }

    /**
     * The products this supplier's inventory answers for.
     *
     * The inverse of BookingProduct::defaultSupplier(), and a list rather than a single
     * case for the same reason that one is a *default*: the day a second hotel source
     * exists, this is where it says so, and every form gated on it narrows correctly
     * without being touched.
     *
     * @return array<int, BookingProduct>
     */
    public function products(): array
    {
        return match ($this) {
            self::TboAir => [BookingProduct::Flight],
            self::TboHotel => [BookingProduct::Hotel],
        };
    }

    /**
     * Whether a rule on this product could ever match this supplier.
     *
     * It could not: a flight context always arrives carrying TboAir, so a flight rule
     * narrowed to TboHotel passes matchesProduct() and fails matchesSupplier() on every
     * booking there will ever be. The rule saves, sits in the list looking live, and
     * charges nothing. That is the combination this refuses.
     *
     * A rule matching every product keeps both: narrowing "all products" to TboAir is a
     * roundabout way of saying flights, but it is not a lie.
     */
    public function appliesToProduct(string $product): bool
    {
        $booking = BookingProduct::tryFrom($product);

        return $booking === null || in_array($booking, $this->products(), true);
    }

    /**
     * Why a supplier is unavailable, for the form to say out loud — same phrasing the
     * Type and Charged-on selects use for their own restrictions.
     */
    public function productRestriction(): ?string
    {
        $products = $this->products();

        if (count($products) === count(BookingProduct::cases())) {
            return null;
        }

        return implode(' and ', array_map(
            fn (BookingProduct $product): string => Str::plural(strtolower($product->label())),
            $products,
        )).' only';
    }

    /**
     * @return array<string, string> value => label, for one product's select
     */
    public static function optionsForProduct(string $product): array
    {
        return array_reduce(
            array_filter(self::cases(), fn (self $supplier): bool => $supplier->appliesToProduct($product)),
            fn (array $carry, self $supplier): array => $carry + [$supplier->value => $supplier->label()],
            [],
        );
    }

    /**
     * Every product's suppliers, for a form that switches between them without a round
     * trip.
     *
     * @return array<string, array<string, string>>
     */
    public static function optionsByProduct(): array
    {
        return array_reduce(
            array_merge(['*'], BookingProduct::values()),
            fn (array $carry, string $product): array => $carry + [$product => self::optionsForProduct($product)],
            [],
        );
    }

    /**
     * @return array<string, string> value => label, for filters and selects
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $carry, self $supplier): array => $carry + [$supplier->value => $supplier->label()],
            [],
        );
    }
}
