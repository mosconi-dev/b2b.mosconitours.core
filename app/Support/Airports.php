<?php

namespace App\Support;

class Airports
{
    /**
     * The full curated airport list.
     *
     * @return array<int, array{code: string, city: string, country: string, country_code: string}>
     */
    public static function all(): array
    {
        return config('airports', []);
    }

    /**
     * The ISO country code an IATA code sits in, or null when we do not carry it.
     *
     * Null for an airport that is not on the curated list, and for one added without a
     * `country_code`. Both are genuinely unknown rather than foreign, and the caller —
     * TravelScopeResolver — is the one that decides what unknown means.
     *
     * Rebuilt per call rather than memoised in a static: the list comes from config,
     * and a test that swaps it would otherwise get the previous test's answer.
     */
    public static function countryCode(string $iata): ?string
    {
        $map = array_column(self::all(), 'country_code', 'code');

        return $map[strtoupper(trim($iata))] ?? null;
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
