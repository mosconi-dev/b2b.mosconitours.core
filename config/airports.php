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
*/

return [
    // Philippines
    ['code' => 'MNL', 'city' => 'Manila', 'country' => 'Philippines'],
    ['code' => 'CEB', 'city' => 'Cebu', 'country' => 'Philippines'],
    ['code' => 'DVO', 'city' => 'Davao', 'country' => 'Philippines'],
    ['code' => 'MPH', 'city' => 'Caticlan', 'country' => 'Philippines'],
    ['code' => 'KLO', 'city' => 'Kalibo', 'country' => 'Philippines'],
    ['code' => 'PPS', 'city' => 'Puerto Princesa', 'country' => 'Philippines'],
    ['code' => 'USU', 'city' => 'Coron', 'country' => 'Philippines'],
    ['code' => 'ILO', 'city' => 'Iloilo', 'country' => 'Philippines'],
    ['code' => 'BCD', 'city' => 'Bacolod', 'country' => 'Philippines'],
    ['code' => 'TAG', 'city' => 'Bohol (Tagbilaran)', 'country' => 'Philippines'],
    ['code' => 'CRK', 'city' => 'Clark', 'country' => 'Philippines'],
    ['code' => 'CGY', 'city' => 'Cagayan de Oro', 'country' => 'Philippines'],
    ['code' => 'GES', 'city' => 'General Santos', 'country' => 'Philippines'],
    ['code' => 'ZAM', 'city' => 'Zamboanga', 'country' => 'Philippines'],
    ['code' => 'TAC', 'city' => 'Tacloban', 'country' => 'Philippines'],
    ['code' => 'DGT', 'city' => 'Dumaguete', 'country' => 'Philippines'],
    ['code' => 'LGP', 'city' => 'Legazpi', 'country' => 'Philippines'],

    // International
    ['code' => 'SIN', 'city' => 'Singapore', 'country' => 'Singapore'],
    ['code' => 'HKG', 'city' => 'Hong Kong', 'country' => 'Hong Kong'],
    ['code' => 'BKK', 'city' => 'Bangkok', 'country' => 'Thailand'],
    ['code' => 'KUL', 'city' => 'Kuala Lumpur', 'country' => 'Malaysia'],
    ['code' => 'CGK', 'city' => 'Jakarta', 'country' => 'Indonesia'],
    ['code' => 'NRT', 'city' => 'Tokyo (Narita)', 'country' => 'Japan'],
    ['code' => 'HND', 'city' => 'Tokyo (Haneda)', 'country' => 'Japan'],
    ['code' => 'ICN', 'city' => 'Seoul', 'country' => 'South Korea'],
    ['code' => 'TPE', 'city' => 'Taipei', 'country' => 'Taiwan'],
    ['code' => 'PVG', 'city' => 'Shanghai', 'country' => 'China'],
    ['code' => 'PEK', 'city' => 'Beijing', 'country' => 'China'],
    ['code' => 'DXB', 'city' => 'Dubai', 'country' => 'UAE'],
    ['code' => 'DOH', 'city' => 'Doha', 'country' => 'Qatar'],
    ['code' => 'DEL', 'city' => 'Delhi', 'country' => 'India'],
    ['code' => 'SYD', 'city' => 'Sydney', 'country' => 'Australia'],
    ['code' => 'LAX', 'city' => 'Los Angeles', 'country' => 'United States'],
    ['code' => 'SFO', 'city' => 'San Francisco', 'country' => 'United States'],
    ['code' => 'LHR', 'city' => 'London', 'country' => 'United Kingdom'],
];
