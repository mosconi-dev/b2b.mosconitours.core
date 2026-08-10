<?php

namespace App\Enums;

/**
 * The kind of partner an agency record represents.
 *
 * The type is descriptive, not authoritative: every agency is an independent
 * permission scope regardless of type, and what its members may do comes only
 * from the roles assigned to them there. Nothing in the authorization layer
 * branches on this enum.
 */
enum AgencyType: string
{
    case MainOffice = 'main_office';
    case Outlet = 'outlet';
    case Itp = 'itp';

    public function label(): string
    {
        return match ($this) {
            self::MainOffice => 'Main Office',
            self::Outlet => 'Outlet',
            self::Itp => 'ITP',
        };
    }

    /**
     * Tailwind pill classes for rendering the type as a badge.
     */
    public function badgeClasses(): string
    {
        return match ($this) {
            self::MainOffice => 'bg-indigo-50 text-indigo-700 ring-indigo-600/20',
            self::Outlet => 'bg-sky-50 text-sky-700 ring-sky-600/20',
            self::Itp => 'bg-teal-50 text-teal-700 ring-teal-600/20',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
