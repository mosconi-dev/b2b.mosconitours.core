<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Active environment
    |--------------------------------------------------------------------------
    |
    | The app-wide default TBO environment ("test" or "live"). This is only the
    | fallback — an admin can override it globally via the Settings page, and a
    | per-user override can take precedence (see TboEnvironmentResolver).
    |
    */

    'default' => env('TBOAIR_ENV', 'test'),

    /*
    |--------------------------------------------------------------------------
    | Per-environment credentials & endpoints
    |--------------------------------------------------------------------------
    |
    | Test and live are fully separate: different agencies, hosts and (crucially)
    | tokens. The token cache is namespaced per environment so a test token is
    | never replayed against live.
    |
    */

    'environments' => [

        'test' => [
            'credentials' => [
                'username' => env('TBOAIR_TEST_USERNAME'),
                'password' => env('TBOAIR_TEST_PASSWORD'),
                'ip_address' => env('TBOAIR_IP_ADDRESS', '127.0.0.1'),
            ],
            'endpoints' => [
                'authentication' => env('TBOAIR_AUTH_URL', 'https://xmloutapi.tboair.com/API/V1/Authenticate/ValidateAgency'),
                'search' => env('TBOAIR_SEARCH_URL', 'https://api-stage.tboair.com/InternalAirService.svc/rest/Search/'),
                'fare_rule' => 'https://api-stage.tboair.com/InternalAirService.svc/rest/FareRule/',
                'fare_quote' => 'https://api-stage.tboair.com/InternalAirService.svc/rest/FareQuote/',
                'ssr' => 'https://api-stage.tboair.com/InternalAirService.svc/rest/SSR/',
                'book' => 'https://xmloutbookingapi.tboair.com/api/v1/Booking/Book',
                'ticket' => 'https://xmloutbookingapi.tboair.com/api/v1/Booking/Ticket',
                'booking_details' => 'https://xmloutbookingapi.tboair.com/api/v1/Booking/GetBookingDetails',
                'release' => 'https://xmloutbookingapi.tboair.com/api/v1/Booking/ReleasePNR/',
            ],
        ],

        'live' => [
            'credentials' => [
                'username' => env('TBOAIR_LIVE_USERNAME'),
                'password' => env('TBOAIR_LIVE_PASSWORD'),
                'ip_address' => env('TBOAIR_IP_ADDRESS', '127.0.0.1'),
            ],
            'endpoints' => [
                'authentication' => 'https://searchapi.tboair.com/api/v1/Authenticate/validateAgency',
                'search' => 'https://tbo-api.tboair.com/InternalAirService.svc/rest/Search/',
                'fare_rule' => 'https://tbo-api.tboair.com/InternalAirService.svc/rest/FareRule/',
                'fare_quote' => 'https://tbo-api.tboair.com/InternalAirService.svc/rest/FareQuote/',
                'ssr' => 'https://tbo-api.tboair.com/InternalAirService.svc/rest/SSR/',
                'book' => 'https://bookingapi.tboair.com/api/v1/Booking/Book',
                'ticket' => 'https://bookingapi.tboair.com/api/v1/Booking/Ticket',
                'booking_details' => 'https://bookingapi.tboair.com/api/v1/Booking/GetBookingDetails',
                'release' => 'https://bookingapi.tboair.com/api/v1/Booking/ReleasePNR',
                'refund' => 'https://bookingapi.tboair.com/api/v1/Booking/RefundApi',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Shared request modes
    |--------------------------------------------------------------------------
    |
    | Authenticate expects the string "API"; Search expects the integer 5.
    |
    */

    'auth_mode' => env('TBOAIR_AUTH_MODE', 'API'),

    'booking_mode' => (int) env('TBOAIR_BOOKING_MODE', 5),

    /*
    |--------------------------------------------------------------------------
    | Token cache & HTTP
    |--------------------------------------------------------------------------
    |
    | cache_key is the BASE key; the effective token key is "{cache_key}:{env}".
    | An admin can override the base key from the Settings page (see Settings).
    |
    */

    'token_ttl' => (int) env('TBOAIR_TOKEN_TTL', 82800), // 23h (TokenId valid 24h)

    'cache_key' => env('TBOAIR_CACHE_KEY', 'tboair.token'),

    // How long a user's search results are cached (seconds). Refreshes within
    // this window are served from cache; after it, the search auto re-runs.
    'search_cache_ttl' => (int) env('TBOAIR_SEARCH_CACHE_TTL', 300), // 5 min

    'timeout' => (int) env('TBOAIR_TIMEOUT', 300),

    'connect_timeout' => (int) env('TBOAIR_CONNECT_TIMEOUT', 10),

    /*
    |--------------------------------------------------------------------------
    | API call logging
    |--------------------------------------------------------------------------
    |
    | When enabled, every Authenticate/Search request and response is persisted
    | to the tbo_air_api_logs table for auditing and debugging.
    |
    */

    'logging' => filter_var(env('TBOAIR_LOGGING', true), FILTER_VALIDATE_BOOLEAN),

];
