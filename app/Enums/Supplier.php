<?php

namespace App\Enums;

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
