# TBO Air — Current Implementation

What exists in the codebase today. **Only Authenticate + Search are implemented end-to-end**; the
whole booking lifecycle (FareRule → FareQuote → SSR → Book → Ticket → manage) is **not built** —
though the endpoint URLs already sit dormant in config. Namespace: `App\Services\TboAir`.

## Request flow (search)

```
flights.blade.php (Alpine: flightSearch)
   └─ POST /flights/search  (can:flight.search)
        └─ SearchFlightsRequest → SearchInput DTO
             └─ FlightController::search
                  └─ FlightSearchCache::remember(userId, env, input, …)   // 5-min per-user/env cache
                       └─ TboAirService::search(input)
                            ├─ token()  → Cache::remember('tboair.token:{env}', ~23h, authenticate())
                            ├─ doSearch(input, token) → TboAirClient::search(payload)
                            │      └─ HTTP POST {search_url}  (+ TboAirApiLog row)
                            │      └─ ErrorCode 6 → forget token, re-auth once, retry
                            └─ FlightResultTransformer → FlightOffer[]
                  └─ JSON { results: [...], traceId, currency }
```

The `TboAirClient` is **bound per request** (`AppServiceProvider`) to the environment resolved by
`TboEnvironmentResolver`, so every call reflects the current global/per-user env.

## Config — `config/tboair.php`

| Key | Default | Notes |
| --- | --- | --- |
| `default` | `env('TBOAIR_ENV','test')` | app-wide fallback env |
| `environments.{test,live}` | — | each: `credentials` (`username`,`password`,`ip_address`) + `endpoints` |
| `auth_mode` | `env('TBOAIR_AUTH_MODE','API')` | Authenticate sends the string `"API"` |
| `booking_mode` | `(int) env('TBOAIR_BOOKING_MODE',5)` | Search sends integer `5` |
| `token_ttl` | `82800` (23h) | inside the ~24h token validity |
| `cache_key` | `tboair.token` | base key; effective is `{cache_key}:{env}` |
| `search_cache_ttl` | `300` (5 min) | **safely under the 15-min TraceId window** |
| `timeout` / `connect_timeout` | `300` / `10` | seconds |
| `logging` | `true` | toggles `TboAirApiLog` writes |

**Endpoint keys — present for both `test` and `live`** (⚠️ only `authentication` + `search` are
implemented; the rest are dormant strings): `authentication`, `search`, `fare_rule`, `fare_quote`,
`ssr`, `book`, `ticket`, `booking_details`, `release`, and `refund` (**live only**).

- Test search/fare/ssr hosts: `api-stage.tboair.com/InternalAirService.svc/rest/…`; test auth:
  `xmloutapi.tboair.com`; test book/ticket: `xmloutbookingapi.tboair.com/api/v1/Booking/…`.
- Live: `tbo-api.tboair.com/InternalAirService.svc/rest/…`, auth `searchapi.tboair.com`, booking
  `bookingapi.tboair.com/api/v1/Booking/…`.

> ⚠️ **API-generation caveat:** search/fare/ssr use the older `InternalAirService.svc/rest/` hosts,
> while the [help page](https://searchapi.tboair.com/Help) documents newer `api/{v}/Search`,
> `Detail/FareQuote`, `Booking/Book` REST routes. Before Phase 1, confirm with TBO which generation
> our agency is on and whether the dormant config URLs (and their field names) are current.

## Service layer (`app/Services/TboAir/`)

| Class | Key public API | Purpose |
| --- | --- | --- |
| `TboAirService` | `search(SearchInput): array` · `token(): string` · `environment(): string` · `tokenTtl(): int` · `cacheKey(): string` | Orchestrates token caching + search; single `ErrorCode 6` re-auth retry; builds the Search payload; maps errors |
| `TboAirClient` | `authenticate(): array` · `search(array): array` · `environment()` · `ipAddress()` | Thin HTTP wrapper for one env; logs every call via `record()`; masks `Password`; **omits `Accept: application/json`** (TBO gateway can hang) |
| `TboAirConfig` | `static for(string $env): array` | Flattens base + `environments[$env]` into the client's config shape (`auth_url`, `search_url`, `endpoints`, creds, timeouts) |
| `TboEnvironmentResolver` | `resolve(?User): string` · `normalize(): string` | **per-user override → global setting → config default**; per-user `live` requires `supplier.tbo.live` (else falls back to `test`) |
| `FlightSearchCache` | `remember(userId, env, SearchInput, Closure)` · `key(...)` | Per-user + per-env result cache: `flight_search:{env}:{user}:{hash}` |
| `FlightResultTransformer` | `transform(array): FlightOffer[]` | Envelope-agnostic mapping of TBO search results (nested-list and flat `TripIndicator` forms) |
| `Exceptions\TboAirException` | `static auth()` · `isAuthError()` | Marks auth/session errors to drive the single re-auth retry |
| `DTO\SearchInput` | readonly: `tripType,cabin,adults,children,infants,segments,returnDate` · `toArray()` | Immutable search request; `toArray()` feeds the cache key |
| `DTO\FlightOffer` | readonly incl. `resultIndex, source, isLcc, isRefundable, price{…}, trips[]` | Normalized offer the frontend renders; **carries `resultIndex`** for downstream booking |

Enums (`app/Enums/`): `TripType` (`journeyType()` → 1/2/3), `CabinClass` (`tboCode()` → 1/2/3/4/6).
Airports helper: `app/Support/Airports.php` (`all/codes/extractCode/isDomestic`).

## HTTP surface

- `FlightController::index()` → `flights` view; `search()` → cached search JSON, `TboAirException`
  → HTTP **502**.
- `SearchFlightsRequest` — normalizes/validates segments (IATA extraction, `origin != destination`,
  pax bounds, `returnDate required_if round`), `searchInput()` builds the DTO.
- Routes (`routes/web.php`, `auth`+`verified`): `GET /flights` (`can:flight.view`),
  `POST /flights/search` (`can:flight.search`), `GET /api-logs` + `/api-logs/{id}` (`can:apilog.view`).
- RBAC (`config/rbac.php`): `flight` = `view,search,book,issue`; `apilog` = `view`; `supplier.tbo` =
  `view,sync,manage,live`; a forward-looking `booking` module (`view,create,cancel,refund`, `route
  => null`). **`flight.book`/`flight.issue` and `booking.*` are declared but unused** — ready for the
  booking phases.

## Logging

- `TboAirApiLog` (`tbo_air_api_logs`): `type` (`authenticate`|`search` today), `environment`,
  `endpoint`, `status_code`, `successful`, `duration_ms`, `user_id`, `request`/`response` (json),
  `error`; `summary()` renders `MNL → CEB` for searches. Passwords masked before storage.
- `ApiLogController` — global `/api-logs` list (response blob excluded from the list query;
  lazy-loaded on expand). Also surfaced **per user** at `/admin/users/{user}/logs` (API calls tab).

## Console commands (`app/Console/Commands/`)

- `tboair:auth {--fresh}` — isolate/verify the Authenticate call, print the TokenId or a timed failure.
- `tboair:logs {id?} {--limit=} {--type=} {--failed}` — inspect recent API-log rows / one row's payloads.
- `tboair:search {origin} {destination} {departure} {--adults=} {--return=} …` — live search smoke test
  (only works from a whitelisted server).

## Frontend

- `resources/views/flights.blade.php` + `resources/js/app.js` (`flightSearch`, `airportField`,
  `x-flatpickr`): full search UI (trip type, pax, cabin, multi-city up to 6, date pickers), client-side
  sort/filter of results, result cards with legs/layovers/refundable/LCC badges.
- **Placeholders / not wired:** the **"Select" button is inert** (`title="Booking coming soon"`), and
  the "Recent searches" / "Recent bookings" blocks are hardcoded sample data.

## Tests & fixtures

Fixtures: `tests/Fixtures/tboair/` (`authenticate.json`, `search-oneway.json`, `search-flat.json`).
Coverage: `FlightSearchTest` (gate, payload mapping, token caching, ErrorCode-6 single re-auth, 502,
round-trip), `FlightSearchCacheTest` + `Unit/FlightSearchCacheTest` (per-user/env cache + key),
`Unit/FlightResultTransformerTest` (envelope-agnostic parsing), `Unit/SearchInputTest`, and
`Feature/TboAir/*` (env resolver, per-user env, live routing/logging). Plus `ApiLogTest` and the
admin settings/logs tests.

## Environment variables

`TBOAIR_ENV`, `TBOAIR_TEST_USERNAME`, `TBOAIR_TEST_PASSWORD`, `TBOAIR_LIVE_USERNAME`,
`TBOAIR_LIVE_PASSWORD`, `TBOAIR_IP_ADDRESS`, `TBOAIR_AUTH_MODE`, `TBOAIR_BOOKING_MODE`,
`TBOAIR_TOKEN_TTL`, `TBOAIR_CACHE_KEY`, `TBOAIR_SEARCH_CACHE_TTL`, `TBOAIR_TIMEOUT`,
`TBOAIR_CONNECT_TIMEOUT`, `TBOAIR_LOGGING`, and the per-endpoint overrides `TBOAIR_AUTH_URL` /
`TBOAIR_SEARCH_URL`. (Live credentials are currently unset — live auth fails until provided.)

## Gaps for the booking lifecycle

Not implemented (client has only `authenticate()` + `search()`; the following config URLs are unused):
**FareRule, FareQuote, SSR, Book, Ticket, GetBookingDetails, ReleasePNR, Refund.** There is **no
booking/PNR model, migration, controller, or route**, no passenger DTO, and no fare-quote/ticketing
transformers. See `03-implementation-plan.md` for how to close these gaps.
