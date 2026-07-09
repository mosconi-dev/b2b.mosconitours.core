# TBO Air — Current Implementation

What exists in the codebase today. The **search → price → book-as-quote** path is built end-to-end:
**Authenticate, Search, FareRule, FareQuote, SSR** (Phases 1–3), plus a persisted **Booking** domain and
a full-page booking **wizard**. The money step — **Book + Ticket** (Phase 4) — and the manage endpoints
(GetBookingDetails, ReleasePNR, Refund) are **not built**; their config URLs sit dormant. Namespaces:
`App\Services\TboAir` (supplier) and `App\Services\Booking` (our booking domain).

## Request flow

**Search** (flights page):

```
flights.blade.php (Alpine: flightSearch)
   └─ POST /flights/search  (can:flight.search)
        └─ SearchFlightsRequest → SearchInput DTO
             └─ FlightController::search
                  └─ FlightSearchCache::remember(userId, env, input, …)   // 5-min per-user/env cache
                       └─ TboAirService::search → TboAirClient::search (+ TboAirApiLog)
                  └─ JSON { results: FlightOffer[], traceId, currency }
```

**Select → price → book** (wizard). "Select" carries `{traceId, resultIndex, oldFare, q, …}` to the wizard:

```
GET /bookings/create  (can:booking.create)
   └─ BookingController::create → TboAirService::fareQuote(SelectionInput)   // the single re-price
        └─ (LCC) TboAirService::ssr(SelectionInput)                          // baggage/meal options
        └─ view bookings/create (Alpine: bookingWizard)  — price-change gate only if the fare moved
POST /bookings  (can:booking.create)
   └─ StoreBookingRequest → BookingService::createFromQuote(user, selection, passengers, contact)
        └─ re-prices server-side (FareQuote) → persists a `quoted` Booking, no TBO commitment
```

`TboAirClient` is **bound per request** (`AppServiceProvider`) to the env resolved by
`TboEnvironmentResolver`, so every call reflects the current global/per-user environment.

## Config — `config/tboair.php`

| Key | Default | Notes |
| --- | --- | --- |
| `default` | `env('TBOAIR_ENV','test')` | app-wide fallback env |
| `environments.{test,live}` | — | each: `credentials` + `endpoints` |
| `auth_mode` | `'API'` | Authenticate sends the string `"API"` |
| `booking_mode` | `5` | Search sends integer `5` |
| `token_ttl` | `82800` (23h) | inside the ~24h token validity |
| `search_cache_ttl` | `300` (5 min) | **safely under the 15-min TraceId window** |
| `recent_ttl` | `86400` (1 day) | per-user "recent searches" shortcut cache |
| `timeout` / `connect_timeout` | `300` / `10` | seconds |
| `logging` | `true` | toggles `TboAirApiLog` writes |

**Endpoint keys (both `test` + `live`):** `authentication`, `search`, `fare_rule`, `fare_quote`, `ssr`,
`book`, `ticket`, `booking_details`, `release`, `refund` (**live only**). ✅ implemented today:
`authentication`, `search`, `fare_rule`, `fare_quote`, `ssr`. ⚠️ dormant: `book`, `ticket`,
`booking_details`, `release`, `refund`.

- Test search/fare/ssr: `api-stage.tboair.com/InternalAirService.svc/rest/…`; auth: `xmloutapi.tboair.com`;
  book/ticket host: `xmloutbookingapi.tboair.com/api/v1/Booking/…` (dormant).
- Live: `tbo-api.tboair.com/…`, auth `searchapi.tboair.com`, booking `bookingapi.tboair.com/…`.

## Service layer

### `app/Services/TboAir/`

| Class | Key public API | Purpose |
| --- | --- | --- |
| `TboAirService` | `search(SearchInput): array` · `fareQuote(SelectionInput): FareQuote` · `fareRule(SelectionInput): FareRule` · `ssr(SelectionInput): Ssr` · `token()` · `environment()` · `tokenTtl()` · `cacheKey()` | Orchestrates token caching + the five implemented calls; single `ErrorCode 6` re-auth retry; maps errors |
| `TboAirClient` | `authenticate()` · `search()` · `fareQuote()` · `fareRule()` · `ssr()` · `environment()` · `ipAddress()` | Thin per-env HTTP wrapper; logs every call; masks `Password`; omits `Accept: application/json` (TBO gateway can hang) |
| `TboAirConfig` | `static for(env): array` | Flattens base + `environments[env]` into the client config shape |
| `TboEnvironmentResolver` | `resolve(?User)` · `normalize()` | per-user override → global setting → config default; per-user `live` requires `supplier.tbo.live` |
| `FlightSearchCache` | `remember(userId, env, SearchInput, Closure)` · `key(...)` | Per-user + per-env result cache: `flight_search:{env}:{user}:{hash}` (5 min) |
| `RecentSearchStore` | `get(userId)` · `put(userId, array)` · `key(userId)` | Per-user "recent searches" list in the cache (`flight_recent:{user}`, ~1 day); client owns list shape |
| `FlightResultTransformer` | `transform(array): FlightOffer[]` | Envelope-agnostic mapping of TBO search results |
| `Exceptions\TboAirException` | `static auth()` · `isAuthError()` · `isTimeout()` | Drives the re-auth retry; timeout vs other for messaging |

**DTOs** (`app/Services/TboAir/DTO/`): `SearchInput`, `FlightOffer` (carries `resultIndex`),
`SelectionInput` (`traceId` + `resultIndex` — the price/SSR request), `FareQuote` (offered fare, price
breakdown, `isLcc`, `isRefundable`, `isPassportMandatory`, `isPriceChanged`), `FareRule`, `Ssr`
(baggage + meal options, priced).

### `app/Services/Booking/`

| Class | Key public API | Purpose |
| --- | --- | --- |
| `BookingService` | `createFromQuote(User, SelectionInput, passengers[], contact): Booking` · `transitionTo(Booking, BookingStatus, attrs): Booking` | Re-prices server-side (FareQuote), persists a `quoted` Booking; `transitionTo` is the status seam for Phase 4 |
| `DTO\Passenger` | readonly (`type,title,firstName,lastName,gender,dateOfBirth,passport…,nationality,baggage,meal`) · `isInfant()` · `hasPassport()` · `toArray()` | One passenger the store request builds |
| `Exceptions\BookingException` | — | Domain failures (fare gone, validation) → controller 422 |

Enums (`app/Enums/`): `TripType`, `CabinClass`, **`BookingStatus`** (`quoted` → `booked` → `ticketed`;
`failed`/`cancelled`/`refunded`). Airports helper: `app/Support/Airports.php`.

## Data model

- **`Booking`** (`bookings` table, migrations `2026_07_09_000012` + `…000013`) —
  `#[Fillable]` reference/user/status/currency/fares/passengers(json)/contact(json)/ancillary_total/…;
  `status` cast to `BookingStatus`; `user()` relation. A booking is a **priced quote** until Phase 4.
- Supplier logging: **`TboAirApiLog`** (`tbo_air_api_logs`) — `type`, `environment`, `endpoint`,
  `status_code`, `successful`, `duration_ms`, `user_id`, `request`/`response` (json), `error`;
  `summary()` renders `MNL → CEB`. Passwords masked before storage.

## HTTP surface (`routes/web.php`, `auth`+`verified`)

- `GET /flights` (`can:flight.view`) → `FlightController::index` (injects the cached recent searches).
- `POST /flights/recent` (`can:flight.view`) → persist the per-user recent-search list.
- `POST /flights/search` (`can:flight.search`) → cached search JSON; `TboAirException` → **502** (or
  **504** on a gateway timeout, with a clearer message).
- `POST /flights/fare-quote` · `/flights/fare-rule` · `/flights/ssr` (`can:flight.search`) — the
  select-time detail calls (`FareDetailRequest` → `SelectionInput`).
- `bookings` group: `GET /` (index, `can:booking.view`), `GET /create` + `POST /` (`can:booking.create`),
  `GET /{booking}` (show, `can:booking.view`).
- `GET /api-logs` + `/api-logs/{id}` (`can:apilog.view`).

FormRequests: `SearchFlightsRequest`, `FareDetailRequest`, `StoreBookingRequest`,
`StoreRecentSearchesRequest`.

## Frontend

- **Flights** (`resources/views/flights.blade.php` + `resources/js/app.js`: `flightSearch`,
  `airportField`, `x-flatpickr`) — full search form (extracted to the shared partial
  `resources/views/flights/form.blade.php`), client-side sort/filter, result cards with
  legs/layovers/refundable/LCC badges and **12-hour AM/PM** times. **"Select" is live** and hands off to
  the wizard. Result cards clear only once a new search is submitted (`x-show="!loading"`).
- **Recent searches are real** — seeded from the per-user cache (`RecentSearchStore`), appended on each
  successful search (deduped, capped at 6, ~1-day TTL); click to refill. (Recent *bookings* on the
  landing page are still sample data.)
- **Booking wizard** (`resources/views/bookings/create.blade.php` + `bookingWizard` in `app.js`;
  stepper `_stepper.blade.php`) — Select Flight → **Guest Details** → **Add-ons** → Payment →
  Confirmation. Guest Details uses a left section-rail + contained form; **the search bar is editable in
  place** (reuses `flights/form.blade.php` via `flightSearch` "embedded" mode) and submitting it hands
  back to the Select Flight page with the new search. A **price-change gate** shows only if the re-price
  differs. **Payment is a stub**; Confirmation shows the saved `quoted` booking.

## Console commands (`app/Console/Commands/`)

`tboair:auth {--fresh}`, `tboair:logs {id?} {--limit=} {--type=} {--failed}`,
`tboair:search {origin} {destination} {departure} …` (live smoke tests need a whitelisted server).

## Tests & fixtures

Fixtures: `tests/Fixtures/tboair/` (`authenticate.json`, `search-*.json`, `farequote.json`, `ssr.json`).
Coverage includes `FlightSearchTest`, `FlightSearchCacheTest` (+ `Unit`), `RecentSearchesTest`,
`Unit/FlightResultTransformerTest`, `Unit/SearchInputTest`, `BookingTest` (create/store, gates,
fare-gone redirect, embedded edit form), `Feature/TboAir/*` (env resolver, per-user env, live routing),
`ApiLogTest`, and the admin settings/logs tests.

## Environment variables

`TBOAIR_ENV`, `TBOAIR_TEST_USERNAME`/`_PASSWORD`, `TBOAIR_LIVE_USERNAME`/`_PASSWORD`, `TBOAIR_IP_ADDRESS`,
`TBOAIR_AUTH_MODE`, `TBOAIR_BOOKING_MODE`, `TBOAIR_TOKEN_TTL`, `TBOAIR_CACHE_KEY`,
`TBOAIR_SEARCH_CACHE_TTL`, `TBOAIR_RECENT_TTL`, `TBOAIR_TIMEOUT`, `TBOAIR_CONNECT_TIMEOUT`,
`TBOAIR_LOGGING`, and per-endpoint overrides `TBOAIR_AUTH_URL` / `TBOAIR_SEARCH_URL`. (Live credentials
are currently unset — live auth fails until provided.)

## Gaps for the booking lifecycle

Not implemented (client lacks these calls; the config URLs are dormant): **Book, Ticket,
GetBookingDetails, ReleasePNR, Refund**. A `Booking` is only ever a **priced quote** — no PNR is held and
no ticket is issued. `BookingService::transitionTo` and the `booked`/`ticketed`/… statuses are the seams
for Phase 4. Seat-map selection is deferred. See `03-implementation-plan.md`.
