<?php

return [

    /*
    |--------------------------------------------------------------------------
    | TBO Air API endpoints
    |--------------------------------------------------------------------------
    |
    | Authenticate returns a TokenId (valid 24h). Search echoes the TokenId and
    | returns a TraceId + Results. Both URLs are configurable so they can be
    | pointed at staging or production without a code change.
    |
    */

    'auth_url' => env('TBOAIR_AUTH_URL', 'https://xmloutapi.tboair.com/API/V1/Authenticate/ValidateAgency'),

    'search_url' => env('TBOAIR_SEARCH_URL', 'https://api-stage.tboair.com/InternalAirService.svc/rest/Search/'),

    /*
    |--------------------------------------------------------------------------
    | Credentials
    |--------------------------------------------------------------------------
    |
    | The public server IP must be whitelisted with TBO. It is sent as the
    | Authenticate "IPAddress" and the Search "EndUserIp".
    |
    */

    'username' => env('TBOAIR_USERNAME'),

    'password' => env('TBOAIR_PASSWORD'),

    'ip_address' => env('TBOAIR_IP_ADDRESS', '127.0.0.1'),

    /*
    |--------------------------------------------------------------------------
    | Request modes
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
