<?php

namespace App\Support;

class Airports
{
    /**
     * The full curated airport list.
     *
     * @return array<int, array{code: string, city: string, country: string}>
     */
    public static function all(): array
    {
        return config('airports', []);
    }

    /**
     * Valid IATA codes, for Rule::in validation.
     *
     * @return array<int, string>
     */
    public static function codes(): array
    {
        return array_column(self::all(), 'code');
    }

    /**
     * Extract a 3-letter IATA code from free-form input such as
     * "Manila (MNL)", "MNL" or " mnl ". Returns null when none is found.
     */
    public static function extractCode(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        // "City (XXX)" — trailing parenthesised code.
        if (preg_match('/\(([A-Za-z]{3})\)\s*$/', $value, $m)) {
            return strtoupper($m[1]);
        }

        // Bare 3-letter code.
        if (preg_match('/^[A-Za-z]{3}$/', $value)) {
            return strtoupper($value);
        }

        // Last resort: first standalone 3-letter token.
        if (preg_match('/\b([A-Za-z]{3})\b/', $value, $m)) {
            return strtoupper($m[1]);
        }

        return null;
    }
}
