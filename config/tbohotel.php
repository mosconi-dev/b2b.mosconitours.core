<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Active environment
    |--------------------------------------------------------------------------
    |
    | The app-wide default TBO Holidays environment ("test" or "live"). Only the
    | fallback — an admin can override it globally, and a per-user override can take
    | precedence (see SupplierEnvironmentResolver).
    |
    */

    'default' => env('TBOHOTEL_ENV', 'test'),

    /*
    |--------------------------------------------------------------------------
    | Per-environment credentials & base URL
    |--------------------------------------------------------------------------
    |
    | Unlike TBO Air there is no Authenticate call and no token: every request
    | carries HTTP Basic Auth. Test and live are separate agencies with separate
    | credentials.
    |
    | The specification (§3) defines one base URL per environment and a
    | "{BaseURL}/{MethodName}" convention, so the endpoint list below is shared and
    | only the base moves.
    |
    | ⚠️ The test base URL is an open question. Spec v2.1 (whose change log records a
    | staging URL change on 21 Apr 2026) says
    |     https://api.tbotechnology.in/HotelAPI
    | while the system live today calls
    |     http://api.tbotechnology.in/TBOHolidays_HotelAPI
    | — a different path and plain HTTP. The spec is the newer of the two and is what
    | is configured here; `php artisan tbohotel:ping` settles it, and the env override
    | means correcting it needs no deploy.
    |
    */

    'environments' => [

        'test' => [
            'credentials' => [
                'username' => env('TBOHOTEL_TEST_USERNAME'),
                'password' => env('TBOHOTEL_TEST_PASSWORD'),
            ],
            'base_url' => env('TBOHOTEL_TEST_BASE_URL', 'https://api.tbotechnology.in/HotelAPI'),
        ],

        'live' => [
            'credentials' => [
                'username' => env('TBOHOTEL_LIVE_USERNAME'),
                'password' => env('TBOHOTEL_LIVE_PASSWORD'),
            ],
            'base_url' => env('TBOHOTEL_LIVE_BASE_URL', 'https://apiwr.tboholidays.com/HotelAPI'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Methods
    |--------------------------------------------------------------------------
    |
    | Our key => TBO's path segment. The casing is TBO's, verbatim and inconsistent
    | ("hotelcodelist" lowercase, "BookingDetailsbasedondate" mixed) — it is copied
    | rather than tidied because a corrected path is a 404.
    |
    */

    'methods' => [
        'search' => 'Search',
        'prebook' => 'PreBook',
        'book' => 'Book',
        'bookingdetail' => 'BookingDetail',
        'cancel' => 'Cancel',
        'countrylist' => 'CountryList',
        'citylist' => 'CityList',
        'hotelcodelist' => 'hotelcodelist',
        'tbohotelcodelist' => 'TBOHotelCodeList',
        'hoteldetails' => 'HotelDetails',
        'bookingdetailsbydate' => 'BookingDetailsbasedondate',
    ],

    // Everything is POST except these two (§11, §13).
    'verbs' => [
        'countrylist' => 'GET',
        'hotelcodelist' => 'GET',
    ],

    /*
    |--------------------------------------------------------------------------
    | Timeouts (seconds)
    |--------------------------------------------------------------------------
    |
    | Per method, from §4 of the spec. Not one global value: a 120-second ceiling on
    | Search would hold a page open for two minutes on a call TBO expects to answer
    | in twenty-three, and a 23-second ceiling on Book would abandon a request that
    | may already have created a reservation.
    |
    */

    'timeouts' => [
        'search' => (int) env('TBOHOTEL_TIMEOUT_SEARCH', 23),
        'prebook' => (int) env('TBOHOTEL_TIMEOUT_PREBOOK', 23),
        'book' => (int) env('TBOHOTEL_TIMEOUT_BOOK', 120),
        'default' => (int) env('TBOHOTEL_TIMEOUT', 60),
    ],

    'connect_timeout' => (int) env('TBOHOTEL_CONNECT_TIMEOUT', 10),

    /*
    |--------------------------------------------------------------------------
    | Throttling
    |--------------------------------------------------------------------------
    |
    | TBO answers 429 when QPS is exceeded (§5), which chunked city searches will
    | reach before anything else does. Retries apply only to calls the caller marks
    | retryable — never Book or Cancel, where a "rejected" request cannot be proven
    | not to have landed.
    |
    */

    'retries' => (int) env('TBOHOTEL_RETRIES', 2),

    'retry_delay' => (int) env('TBOHOTEL_RETRY_DELAY', 1000), // ms, doubling each attempt

    /*
    |--------------------------------------------------------------------------
    | Search behaviour
    |--------------------------------------------------------------------------
    |
    | `booking_window` is TBO's, not ours: the whole search→book flow must finish
    | inside 30 minutes or the BookingCode expires with Status 315. The result cache
    | is deliberately far shorter, so a cached price can never outlive the code that
    | would book it.
    |
    */

    'search_cache_ttl' => (int) env('TBOHOTEL_SEARCH_CACHE_TTL', 600), // 10 min

    'booking_window' => (int) env('TBOHOTEL_BOOKING_WINDOW', 1800), // 30 min

    // Sent as the Search request's ResponseTime: how long TBO may spend gathering
    // results. Kept just under the request timeout so we hear back rather than hang.
    'response_time' => (float) env('TBOHOTEL_RESPONSE_TIME', 20.0),

    /*
    |--------------------------------------------------------------------------
    | API call logging
    |--------------------------------------------------------------------------
    */

    'logging' => filter_var(env('TBOHOTEL_LOGGING', true), FILTER_VALIDATE_BOOLEAN),

];
