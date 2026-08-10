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
| `balance_cache_ttl` | `300` (5 min) | our TBO balance, per env — read on demand, never polled |
| `timeout` / `connect_timeout` | `300` / `10` | seconds |
| `logging` | `true` | toggles `TboAirApiLog` writes |

**Endpoint keys (both `test` + `live`):** `authentication`, `agency_balance`, `search`, `fare_rule`,
`fare_quote`, `ssr`, `book`, `ticket`, `booking_details`, `release`, `refund` (**live only**).
✅ implemented today: `authentication`, `agency_balance`, `search`, `fare_rule`, `fare_quote`, `ssr`.
⚠️ dormant: `book`, `ticket`, `booking_details`, `release`, `refund`.

### ⚠️ Known problems in the dormant endpoint config

Found by checking `config/tboair.php` against TBO's endpoint help page — all Phase 4/5 concerns, but
cheap to fix while the config is open:

| Problem | Detail |
| --- | --- |
| **`refund` is on the wrong controller** | Configured as `…/api/v1/Booking/RefundApi`, but TBO documents `RefundApi` and `RefundRequest` under **`Queues`**, not `Booking`. The refund flow is also documented as a *change request* (`SendChangeRequest.aspx`). |
| **`refund` is live-only** | The `test` environment has no `refund` URL at all, so the flow cannot be exercised before go-live. |
| **Void is missing entirely** | No `Queues/GetVoidAmountDetails` or `Queues/VoidRequest` keys. |
| **`GetLastTicketDate` is missing** | Needed to respect the ticketing time limit on a held non-LCC PNR. |
| **`GetAgencyBalance` is missing** | `Wallet/GetAvailableBalance` — **our** balance with TBO, the pot ticketing draws down. Distinct from the internal agency e-wallet; see the gaps section below. |

- Test search/fare/ssr: `api-stage.tboair.com/InternalAirService.svc/rest/…`; auth: `xmloutapi.tboair.com`;
  book/ticket host: `xmloutbookingapi.tboair.com/api/v1/Booking/…` (dormant).
- Live: `tbo-api.tboair.com/…`, auth `searchapi.tboair.com`, booking `bookingapi.tboair.com/…`.

## Service layer

### `app/Services/TboAir/`

| Class | Key public API | Purpose |
| --- | --- | --- |
| `TboAirService` | `search(SearchInput): array` · `fareQuote(SelectionInput): FareQuote` · `fareRule(SelectionInput): FareRule` · `ssr(SelectionInput): Ssr` · `agencyBalance(fresh:): AgencyBalance` · `hasFundsFor(string): bool` · `token()` · `environment()` · `tokenTtl()` · `cacheKey()` · `balanceCacheKey()` | Orchestrates token caching + the implemented calls; single `ErrorCode 6` re-auth retry; maps errors. **`agencyBalance()` skips the re-auth wrapper** — it is credential-authenticated, with no session token to expire |
| `TboAirClient` | `authenticate()` · `agencyBalance()` · `search()` · `fareQuote()` · `fareRule()` · `ssr()` · `environment()` · `ipAddress()` | Thin per-env HTTP wrapper; logs every call; masks `Password`; omits `Accept: application/json` (TBO gateway can hang) |
| `TboAirConfig` | `static for(env): array` | Flattens base + `environments[env]` into the client config shape |
| `TboEnvironmentResolver` | `resolve(?User)` · `normalize()` | per-user override → global setting → config default; per-user `live` requires `supplier.tbo.live` |
| `FlightSearchCache` | `remember(userId, env, SearchInput, Closure)` · `key(...)` | Per-user + per-env result cache: `flight_search:{env}:{user}:{hash}` (5 min) |
| `RecentSearchStore` | `get(userId)` · `put(userId, array)` · `key(userId)` | Per-user "recent searches" list in the cache (`flight_recent:{user}`, ~1 day); client owns list shape |
| `FlightResultTransformer` | `transform(array): FlightOffer[]` | Envelope-agnostic mapping of TBO search results |
| `ItineraryMapper` | `trips(mixed)` · `legs()` · `lowestAllowance()` · `static isNestedList()` | Normalizes TBO's `Segments` (nested-per-direction **or** flat with `TripIndicator`) into trips of legs; shared by search results and FareQuote so the booking page renders the same itinerary without a second call |
| `FareTotal` | `static for(array $result): float` | The trip total for one result. TBO intermittently blanks a result's headline `Fare` block (no `OfferedFare`/`PublishedFare`, `Tax` reset to 0) while `FareBreakdown` still holds the real numbers — so it falls through alternatives rather than trusting one key, which was showing "PHP 0" and would have written a 0 total onto a booking |
| `TboPassengerMapper` | `static title(): int` · `gender(): int` · `paxType(): int` | Encodes our passenger strings as TBO's Book/Ticket enum ordinals — the only place those integers belong. Folds retired `Ms`/`Mstr` titles; throws rather than guess a missing gender |
| `Exceptions\TboAirException` | `static auth()` · `isAuthError()` · `isTimeout()` | Drives the re-auth retry; timeout vs other for messaging |

**DTOs** (`app/Services/TboAir/DTO/`): `SearchInput`, `FlightOffer` (carries `resultIndex`),
`SelectionInput` (`traceId` + `resultIndex` — the price/SSR request), `FareQuote` (offered fare, price
breakdown, `isLcc`, `isRefundable`, `isPassportMandatory`, `isPriceChanged`, plus **`raw`** — the whole
untransformed response, excluded from `toArray()`), `FareRule`, `Ssr`
(baggage + meal options, priced).

### `app/Services/Booking/`

| Class | Key public API | Purpose |
| --- | --- | --- |
| `BookingService` | `createFromQuote(User, SelectionInput, passengers[], contact): Booking` · `transitionTo(Booking, BookingStatus, attrs): Booking` | Re-prices server-side (FareQuote), persists a `quoted` Booking; `transitionTo` is the status seam for Phase 4. Privately: `withLeadPax()` (exactly one, adult only) and `applyContact()` (fans the shared contact block onto every pax row) |
| `DTO\Passenger` | readonly (`type,title,firstName,lastName,gender,dateOfBirth,passport…,nationality,baggage,meal,isLeadPax`) · `isInfant()` · `isAdult()` · `hasPassport()` · `withLead()` · `toArray()` | One passenger the store request builds. Address/mobile/email are **not** here — they are contact-level and fanned on at persistence |
| `Exceptions\BookingException` | — | Domain failures (fare gone, validation) → controller 422 |

Enums (`app/Enums/`): `TripType`, `CabinClass`, **`BookingStatus`** (`quoted` → `booked` → `ticketed`;
`failed`/`cancelled`/`refunded`). Support helpers: `app/Support/Airports.php`,
`app/Support/Countries.php` (ISO code → country name, for the Book address).

## Data model

- **`Booking`** (`bookings` table, migrations `2026_07_09_000012` + `…000013` + `2026_08_10_000009`
  which adds nullable **`quote_raw`** — the verbatim FareQuote response Book will echo back) —
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
  differs. Confirmation shows the saved `quoted` booking.
- **Guest Details** collects, in the Contact section, the **billing address** (line 1/2, city, 2-letter
  country) and a **mobile country code** beside the number — required because TBO wants them on every
  passenger. Per guest: title (**Mr/Mrs/Miss** only), names, gender, DOB, passport when the fare
  demands it, and a **Lead guest** radio shown only for adults (first adult preselected).
- **Payment** is no longer a bare stub: it shows the agency **wallet balance** and *remaining after
  booking*, reddens a shortfall, blocks **Complete booking** and offers *Request a load*. It is
  advisory — the server re-checks under lock at submit. There is still **no card/gateway step**, and no
  TBO commitment happens here. See [`../wallet/00-overview.md`](../wallet/00-overview.md).

## Console commands (`app/Console/Commands/`)

`tboair:auth {--fresh}`, `tboair:balance {--fresh}` (our TBO balance, with the e-wallet distinction
spelled out in the output), `tboair:logs {id?} {--limit=} {--type=} {--failed}`,
`tboair:search {origin} {destination} {departure} …` (live smoke tests need a whitelisted server).

## Tests & fixtures

Fixtures: `tests/Fixtures/tboair/` (`authenticate.json`, `search-*.json`, `farequote.json`, `ssr.json`).
Coverage includes `FlightSearchTest`, `FlightSearchCacheTest` (+ `Unit`), `RecentSearchesTest`,
`Unit/FlightResultTransformerTest`, `Unit/SearchInputTest`, `Unit/TboPassengerMapperTest` (enum
encoding, retired titles, refused gender), `BookingTest` (create/store, gates, fare-gone redirect,
embedded edit form, **raw quote kept verbatim, contact fan-out, lead-pax rules, title constraint**),
`Feature/TboAir/*` (env resolver, per-user env, live routing, **agency balance** — caching, the real
`Agency.*` envelope, credential-not-token request, gating, and the admin panel making **no** supplier
call on render), `Unit/AgencyBalanceTest` (separators, both envelopes, bccomp edges), `ApiLogTest`,
and the admin settings/logs tests. Balance fixtures: `balance.json` (flat, as documented) and
`balance-agency.json` (nested, as observed).

## Environment variables

`TBOAIR_ENV`, `TBOAIR_TEST_USERNAME`/`_PASSWORD`, `TBOAIR_LIVE_USERNAME`/`_PASSWORD`, `TBOAIR_IP_ADDRESS`,
`TBOAIR_AUTH_MODE`, `TBOAIR_BOOKING_MODE`, `TBOAIR_TOKEN_TTL`, `TBOAIR_CACHE_KEY`,
`TBOAIR_SEARCH_CACHE_TTL`, `TBOAIR_RECENT_TTL`, `TBOAIR_BALANCE_CACHE_TTL`, `TBOAIR_TIMEOUT`,
`TBOAIR_CONNECT_TIMEOUT`,
`TBOAIR_LOGGING`, and per-endpoint overrides `TBOAIR_AUTH_URL` / `TBOAIR_SEARCH_URL`. (Live credentials
are currently unset — live auth fails until provided.)

## Gaps for the booking lifecycle

Not implemented (client lacks these calls; the config URLs are dormant): **Book, Ticket,
GetBookingDetails, ReleasePNR, Refund**. A `Booking` is only ever a **priced quote** — no PNR is held and
no ticket is issued. `BookingService::transitionTo` and the `booked`/`ticketed`/… statuses are the seams
for Phase 4. Seat-map selection is deferred. See `03-implementation-plan.md`.

Three structural gaps surfaced by reading TBO's Book/Ticket method pages (§5 of
`01-tbo-api-reference.md`). Each needs a decision *before* `BookingService::book()` is written:

### 1. ~~We discard most of what Book has to echo back~~ — FIXED

Book re-sends the whole priced itinerary — `NoOfSeatAvailable`, `OperatingCarrier`, `ETicketEligible`,
`FlightStatus`, `StopOver`, `BookingClass`, `AirportName`, `CountryCode`/`CountryName`, and per-passenger
`FareBasisCode`, `FareRestriction`, `ValidatingAirlineCode`, `LastTicketDate`, `OtherCharges`,
`ServiceFee` — with fares "sent exactly as received in the fare quote, without modifications."

**We keep none of them.** `bookings.quote` stores the **transformed** `FareQuote` DTO, not the raw
response: `ItineraryMapper` emits a UI-shaped subset (`airlineCode`, `airport`, `city`, `terminal`,
`duration`, `fareClass`, `stops`, …) and `price`/`fareBreakdown` are narrowed to four keys each.
The transform is lossy in exactly the fields Book wants.

✅ **Fixed** (migration `2026_08_10_000009`): a nullable **`bookings.quote_raw`** json column stores the
FareQuote response verbatim, written beside the snapshot by `BookingService::createFromQuote()`.
`FareQuote::$raw` carries it and is **deliberately excluded from `toArray()`** — that method is both the
`quote` snapshot and the JSON the wizard receives, and the browser has no use for the raw response.
Nullable because pre-existing bookings have none and backfilling would mean re-pricing a moved fare.

### 2. ~~`Passenger` is missing ~8 mandatory Book fields, and the enums are integers~~ — FIXED

Book requires `AddressLine1`, `AddressLine2`, `City`, `CountryCode`, `CountryName`, `Mobile1`,
`Mobile1CountryCode`, `Email`, `IsLeadPax` and `FFAirline`/`FFNumber` on **every** passenger, plus
**integer enums** where we store strings.

✅ **Fixed**, with the shared fields modelled where they belong:

- **Address / mobile / email are contact-level, not per-passenger.** They do not vary per passenger,
  and no agent is typing an address for a two-year-old. They are collected once in the wizard's
  Contact section (`contact.mobileCountryCode`, `addressLine1`, `addressLine2`, `city`, `countryCode`)
  and **fanned onto every pax row** by `BookingService::applyContact()`, so each stored row is
  Book-ready. `CountryName` is **derived** from the code via `App\Support\Countries` (ICU
  `Locale::getDisplayRegion`, falling back to the code), never collected — the two cannot disagree.
- **`Passenger::$isLeadPax`** is the one genuinely per-passenger addition. TBO expects exactly one:
  the wizard uses a radio (`setLeadPax()`), and `BookingService::withLeadPax()` guarantees one anyway
  — the flagged adult, else the first adult. A flag on a child is **not** honoured, and a booking with
  **no adult** is refused.
- **Title is constrained to `Mr` / `Mrs` / `Miss`** — the only three TBO encodes. The wizard used to
  offer `Ms` and `Mstr`, which have no TBO value.
- **`App\Services\TboAir\TboPassengerMapper`** is the string→ordinal encoding (`title()`, `gender()`,
  `paxType()`) and the only place those integers belong. It folds the retired `Ms`/`Mstr` onto
  Miss/Mr for already-stored bookings, and **refuses to guess a missing gender** — TBO requires it and
  airlines match it against ID. Phase 4.1 builds the payload around it.

### 3. ~~TBO's agency balance is invisible to us~~ — FIXED

Ticketing spends **our** balance with TBO, not the internal e-wallet, so a Ticket can fail for
insufficient TBO funds while the booking agency's own wallet is full — after we have already debited
them. (`transitionTo` → `failed` refunds the internal charge, so the agency is made whole; the booking
still fails.)

✅ **Fixed:**

- **`agency_balance` endpoint** in both environments. The test URL is **verified live** (HTTP 200 with
  a real TrackingId) — unusual among the dormant endpoints. It currently answers `IsSuccess: false`
  purely because dev is not IP-whitelisted, the same as `tboair:auth`.
- **`DTO\AgencyBalance`** — decimal **string** + bcmath `covers()`, thousands separators stripped
  first (bcmath reads `"1,500.00"` as `1`). Accepts **both** response envelopes TBO ships: the flat
  documented one and the Authenticate-style `Agency.*` one it actually returned, including TBO's
  `TotalAailableLimit` misspelling. See `01`§5.5.
- **`TboAirService::agencyBalance(fresh:)`** — cached per environment (`balance_cache_ttl`, 5 min),
  **not** wrapped in `withReauth()`: the call is credential-authenticated and carries no session
  token. **`hasFundsFor()`** is the pre-Ticket seam for Phase 4.1.
- **Ops surface:** a *Check now* panel on admin Settings (`supplier.tbo.view`), audit event
  `tbo.balance_checked`, and `php artisan tboair:balance {--fresh}`.
- **Read on demand, never on page render** — the call mints a TokenId, and TBO's guide still claims
  one token per day, so polling risks churning the token an in-flight booking is using.

Also fixed while here: `TboAirService::firstError()` only looked for **nested** `Error` objects, so the
**flat** `ErrorMessage` these credential calls use was dropped, and a present-but-empty message beat
the fallback and rendered a blank error.
