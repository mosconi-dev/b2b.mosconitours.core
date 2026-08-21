<?php

/*
|--------------------------------------------------------------------------
| Curated airport list
|--------------------------------------------------------------------------
|
| Single source of truth for the front-end Origin/Destination picker and for
| server-side validation (App\Support\Airports). Codes are IATA 3-letter
| codes sent verbatim to the TBO Air API. Expand as needed.
|
| `country` is the display name the picker shows. `country_code` is the ISO 3166-1
| alpha-2 code the domestic/international classifier compares — TBO answers with codes
| ("PH"), not names, so the two must be able to meet. An entry added without a
| `country_code` classifies as international, which is the safe direction but is
| wrong for a Philippine airport, so fill it in.
|
*/

return [
    // Philippines
    ['code' => 'MNL', 'city' => 'Manila', 'country' => 'Philippines', 'country_code' => 'PH'],
    ['code' => 'CEB', 'city' => 'Cebu', 'country' => 'Philippines', 'country_code' => 'PH'],
    ['code' => 'DVO', 'city' => 'Davao', 'country' => 'Philippines', 'country_code' => 'PH'],
    ['code' => 'MPH', 'city' => 'Caticlan', 'country' => 'Philippines', 'country_code' => 'PH'],
    ['code' => 'KLO', 'city' => 'Kalibo', 'country' => 'Philippines', 'country_code' => 'PH'],
    ['code' => 'PPS', 'city' => 'Puerto Princesa', 'country' => 'Philippines', 'country_code' => 'PH'],
    ['code' => 'USU', 'city' => 'Coron', 'country' => 'Philippines', 'country_code' => 'PH'],
    ['code' => 'ILO', 'city' => 'Iloilo', 'country' => 'Philippines', 'country_code' => 'PH'],
    ['code' => 'BCD', 'city' => 'Bacolod', 'country' => 'Philippines', 'country_code' => 'PH'],
    ['code' => 'TAG', 'city' => 'Bohol (Tagbilaran)', 'country' => 'Philippines', 'country_code' => 'PH'],
    ['code' => 'CRK', 'city' => 'Clark', 'country' => 'Philippines', 'country_code' => 'PH'],
    ['code' => 'CGY', 'city' => 'Cagayan de Oro', 'country' => 'Philippines', 'country_code' => 'PH'],
    ['code' => 'GES', 'city' => 'General Santos', 'country' => 'Philippines', 'country_code' => 'PH'],
    ['code' => 'ZAM', 'city' => 'Zamboanga', 'country' => 'Philippines', 'country_code' => 'PH'],
    ['code' => 'TAC', 'city' => 'Tacloban', 'country' => 'Philippines', 'country_code' => 'PH'],
    ['code' => 'DGT', 'city' => 'Dumaguete', 'country' => 'Philippines', 'country_code' => 'PH'],
    ['code' => 'LGP', 'city' => 'Legazpi', 'country' => 'Philippines', 'country_code' => 'PH'],

    // International
    ['code' => 'SIN', 'city' => 'Singapore', 'country' => 'Singapore', 'country_code' => 'SG'],
    ['code' => 'HKG', 'city' => 'Hong Kong', 'country' => 'Hong Kong', 'country_code' => 'HK'],
    ['code' => 'BKK', 'city' => 'Bangkok', 'country' => 'Thailand', 'country_code' => 'TH'],
    ['code' => 'KUL', 'city' => 'Kuala Lumpur', 'country' => 'Malaysia', 'country_code' => 'MY'],
    ['code' => 'CGK', 'city' => 'Jakarta', 'country' => 'Indonesia', 'country_code' => 'ID'],
    ['code' => 'NRT', 'city' => 'Tokyo (Narita)', 'country' => 'Japan', 'country_code' => 'JP'],
    ['code' => 'HND', 'city' => 'Tokyo (Haneda)', 'country' => 'Japan', 'country_code' => 'JP'],
    ['code' => 'ICN', 'city' => 'Seoul', 'country' => 'South Korea', 'country_code' => 'KR'],
    ['code' => 'TPE', 'city' => 'Taipei', 'country' => 'Taiwan', 'country_code' => 'TW'],
    ['code' => 'PVG', 'city' => 'Shanghai', 'country' => 'China', 'country_code' => 'CN'],
    ['code' => 'PEK', 'city' => 'Beijing', 'country' => 'China', 'country_code' => 'CN'],
    ['code' => 'DXB', 'city' => 'Dubai', 'country' => 'UAE', 'country_code' => 'AE'],
    ['code' => 'DOH', 'city' => 'Doha', 'country' => 'Qatar', 'country_code' => 'QA'],
    ['code' => 'DEL', 'city' => 'Delhi', 'country' => 'India', 'country_code' => 'IN'],
    ['code' => 'SYD', 'city' => 'Sydney', 'country' => 'Australia', 'country_code' => 'AU'],
    ['code' => 'LAX', 'city' => 'Los Angeles', 'country' => 'United States', 'country_code' => 'US'],
    ['code' => 'SFO', 'city' => 'San Francisco', 'country' => 'United States', 'country_code' => 'US'],
    ['code' => 'LHR', 'city' => 'London', 'country' => 'United Kingdom', 'country_code' => 'GB'],
];
