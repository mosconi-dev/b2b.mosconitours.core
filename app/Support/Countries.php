<?php

namespace App\Support;

use Locale;

class Countries
{
    /**
     * The English display name for a 2-letter ISO country code ("PH" → "Philippines").
     *
     * TBO wants both the code and the name on every passenger address, so the name is
     * resolved rather than collected — one less field for an agent to type, and it
     * cannot drift from the code.
     *
     * Falls back to the code itself when ICU cannot resolve it, so a caller always has
     * something to send. ICU answers an unresolvable code with the code, and reserved
     * codes such as "ZZ" with "Unknown Region" — neither is a usable country name.
     */
    public static function name(string $code): string
    {
        $code = strtoupper(trim($code));

        if ($code === '') {
            return '';
        }

        // getDisplayRegion expects a locale, not a bare region — hence the leading dash.
        $name = Locale::getDisplayRegion('-'.$code, 'en');

        return $name === '' || $name === $code || $name === 'Unknown Region'
            ? $code
            : $name;
    }
}
