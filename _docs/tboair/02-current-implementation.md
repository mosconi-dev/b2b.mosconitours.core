# TBO Air — Current Implementation

What exists in the codebase today. The **search → price → book → ticket** path is built end-to-end:
**Authenticate, Search, FareRule, FareQuote, SSR, GetAgencyBalance, Book, Ticket, GetBookingDetails**,
plus a persisted **Booking** domain, a full-page booking **wizard**, and a permission-gated ticketing
action. Namespaces: `App\Services\TboAir` (supplier) and `App\Services\Booking` (our booking domain).

> ✅ **Book has been proven against TBO.** On 2026-08-10 booking `MT-FBVMSJVR` created **PNR `984XIX`**
> on the test environment (TBO `BookingId 75133`), read back through GetBookingDetails as
> `Successful`, with the agency wallet correctly debited. **Ticket has still never been called** — it
> is the same payload plus that PNR, so the shape is proven, but the call itself is untested.

Still not built: **ReleasePNR, Void, Refund** (Phase 5), and the **domestic round-trip two-PNR** split
(see the gaps section).

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
GET /flights/book  (can:booking.create, can:flight.issue)
   └─ BookingController::create → TboAirService::fareQuote(SelectionInput)   // the single re-price
        └─ (LCC) TboAirService::ssr(SelectionInput)                          // baggage/meal options
        └─ view bookings/create (Alpine: bookingWizard)  — price-change gate only if the fare moved
POST /flights/bookings  (can:booking.create, can:flight.issue)
   └─ StoreBookingRequest → BookingService::createFromQuote(user, selection, passengers, contact)
        └─ re-prices server-side (FareQuote) → persists a `quoted` Booking, no TBO commitment
```

**Book → Ticket** (booking detail page, Phase 4.1). Nothing exists at the airline until one of these:

```
POST /bookings/{booking}/book   (can:flight.book)    — non-LCC only, holds a PNR, spends nothing
POST /bookings/{booking}/issue  (can:flight.issue)   — LCC: books + issues; non-LCC: tickets the PNR
   └─ BookingService::book() / issue()
        ├─ Cache::lock("booking:{id}:write")         — one writer at a time
        ├─ guard environment + status (+ PNR for a non-LCC ticket)
        ├─ TboAirService::book()/ticket() → TboBookPayload::for(...)
        ├─ persist PNR/BookingId immediately
        ├─ ambiguous? → GetBookingDetails → still ambiguous? → leave alone, raise `unresolved`
        └─ transitionTo(Booked | Ticketed | Failed)
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
✅ implemented today: `authentication`, `agency_balance`, `search`, `fare_rule`, `fare_quote`, `ssr`,
**`book`, `ticket`, `booking_details`**. ⚠️ still dormant: `release`, `refund` (Phase 5).

### ⚠️ Known problems in the remaining dormant config (Phase 5)

Found by checking `config/tboair.php` against TBO's endpoint help page. The Phase 4 entries have since
been fixed; these are what is left, all Phase 5:

| Problem | Detail |
| --- | --- |
| **`refund` is on the wrong controller** | Configured as `…/api/v1/Booking/RefundApi`, but TBO documents `RefundApi` and `RefundRequest` under **`Queues`**, not `Booking`. The refund flow is also documented as a *change request* (`SendChangeRequest.aspx`). |
| **`refund` is live-only** | The `test` environment has no `refund` URL at all, so the flow cannot be exercised before go-live. |
| **Void is missing entirely** | No `Queues/GetVoidAmountDetails` or `Queues/VoidRequest` keys. |
| **`GetLastTicketDate` is missing** | Needed to respect the ticketing time limit on a held non-LCC PNR. |

- Test search/fare/ssr: `api-stage.tboair.com/InternalAirService.svc/rest/…`; auth: `xmloutapi.tboair.com`;
  book/ticket/booking-details host: `xmloutbookingapi.tboair.com/api/v1/Booking/…`.
- Live: `tbo-api.tboair.com/…`, auth `searchapi.tboair.com`, booking `bookingapi.tboair.com/…`.

## Service layer

### `app/Services/TboAir/`

| Class | Key public API | Purpose |
| --- | --- | --- |
| `TboAirService` | `search(SearchInput): array` · `fareQuote(SelectionInput): FareQuote` · `fareRule(SelectionInput): FareRule` · `ssr(SelectionInput): Ssr` · `agencyBalance(fresh:): AgencyBalance` · `hasFundsFor(string): bool` · `book(Booking): BookingResult` · `ticket(Booking, ?pnr): BookingResult` · `bookingDetails(pnr): BookingResult` · `token()` · `environment()` · `tokenTtl()` · `cacheKey()` · `balanceCacheKey()` | Orchestrates token caching + the implemented calls; single `ErrorCode 6` re-auth retry; maps errors. **`agencyBalance()` skips the re-auth wrapper** — it is credential-authenticated, with no session token to expire |
| `TboAirClient` | `authenticate()` · `agencyBalance()` · `search()` · `fareQuote()` · `fareRule()` · `ssr()` · `book()` · `ticket()` · `bookingDetails()` · `environment()` · `ipAddress()` | Thin per-env HTTP wrapper; logs every call; masks `Password`; omits `Accept: application/json` (TBO gateway can hang) |
| `TboAirConfig` | `static for(env): array` | Flattens base + `environments[env]` into the client config shape |
| `TboEnvironmentResolver` | `resolve(?User)` · `normalize()` | per-user override → global setting → config default; per-user `live` requires `supplier.tbo.live` |
| `FlightSearchCache` | `remember(userId, env, SearchInput, Closure)` · `key(...)` | Per-user + per-env result cache: `flight_search:{env}:{user}:{hash}` (5 min) |
| `RecentSearchStore` | `get(userId)` · `put(userId, array)` · `key(userId)` | Per-user "recent searches" list in the cache (`flight_recent:{user}`, ~1 day); client owns list shape |
| `FlightResultTransformer` | `transform(array): FlightOffer[]` | Envelope-agnostic mapping of TBO search results |
| `ItineraryMapper` | `trips(mixed)` · `legs()` · `lowestAllowance()` · `static isNestedList()` | Normalizes TBO's `Segments` (nested-per-direction **or** flat with `TripIndicator`) into trips of legs; shared by search results and FareQuote so the booking page renders the same itinerary without a second call |
| `TboBookPayload` | `static for(Booking, token, ip, ?userAgent, ?pnr): array` | Builds the Book/Ticket request body. **One builder for both** — Ticket is this payload plus a `PNR` (null for LCC). Segments and fare go back verbatim from `quote_raw`; the two search-only fields (`NoOfSeatAvailable`, `ResultType`) are restored from their own columns. Pure assembly, no network |
| `FareTotal` | `static for(array $result): float` | The trip total for one result. TBO intermittently blanks a result's headline `Fare` block (no `OfferedFare`/`PublishedFare`, `Tax` reset to 0) while `FareBreakdown` still holds the real numbers — so it falls through alternatives rather than trusting one key, which was showing "PHP 0" and would have written a 0 total onto a booking |
| `TboPassengerMapper` | `static title(): string` · `gender(): int` · `paxType(): int` | The supplier-boundary encoding. `Type` and `Gender` are integers; **`Title` is the word** — TBO's doc page says ordinal and a real Book refused `0`. Folds retired `Ms`/`Mstr`; throws rather than guess a missing gender |
| `Exceptions\TboAirException` | `static auth()` · `isAuthError()` · `isTimeout()` | Drives the re-auth retry; timeout vs other for messaging |

**DTOs** (`app/Services/TboAir/DTO/`): `SearchInput`, `FlightOffer` (carries `resultIndex`),
`SelectionInput` (`traceId` + `resultIndex` — the price/SSR request), `FareQuote` (offered fare, price
breakdown, `isLcc`, `isRefundable`, `isPassportMandatory`, `isPriceChanged`, plus **`raw`** — the whole
untransformed response, excluded from `toArray()`), `FareRule`, `Ssr`
(baggage + meal options, priced).

### `app/Services/Booking/`

**Booking abilities are split by risk.** `booking.create` quotes; **`flight.book`** holds a PNR and
costs nothing; **`flight.issue`** spends. Holding additionally **requires `flight.issue`**: a
reservation nobody here is allowed to complete leaves seats held at the airline until someone releases
them.

**The agency e-wallet is checked at *Complete booking*, not at ticketing** — that is where the debit
happens. An overdraw is refused by `WalletService::debit()` with the figures named ("Insufficient
wallet balance: 100.00 available, 6,400.00 required.") and the whole booking rolls back: no booking
row, no ledger entry, balance untouched. The Payment step warns before submit.

| Class | Key public API | Purpose |
| --- | --- | --- |
| `BookingService` | `createFromQuote(...): Booking` · **`book(Booking, ?userAgent): Booking`** · **`issue(Booking, ?userAgent): Booking`** · `transitionTo(Booking, BookingStatus, attrs): Booking` | Re-prices server-side (FareQuote), persists a `quoted` Booking, and owns the money step. `book()` holds a non-LCC PNR; `issue()` tickets (LCC straight from `quoted`). Privately: `withLeadPax()` (exactly one, adult only), `applyContact()` (fans the shared contact block onto every pax row), `withBookingLock()`, `resolve()`, `rememberSupplierIds()`, `guardSupplierFunds()`, `guardEnvironment()` |
| `DTO\Passenger` | readonly (`type,title,firstName,lastName,gender,dateOfBirth,passport…,nationality,baggage,meal,isLeadPax`) · `isInfant()` · `isAdult()` · `hasPassport()` · `withLead()` · `toArray()` | One passenger the store request builds. Address/mobile/email are **not** here — they are contact-level and fanned on at persistence |
| `Exceptions\BookingException` | `static unresolved(Booking, ?pnr)` · `$unresolved` | Domain failures (fare gone, validation) → controller 422. **`unresolved`** marks the one case that is not a failure: the supplier's answer could not be established, the booking keeps its status, and a person must look |

Enums (`app/Enums/`): `TripType`, `CabinClass`, **`TboBookingStatus`** (TBO's ten codes; `isAmbiguous()`
marks the four that mean "unknown" and `toBookingStatus()` returns **null** for them rather than
guessing — see `01`§5.3), **`BookingStatus`** (`quoted` → `booked` → `ticketed`;
`failed`/`cancelled`/`refunded`). Support helpers: `app/Support/Airports.php`,
`app/Support/Countries.php` (ISO code → country name, for the Book address).

## Data model

- **`Booking`** (`bookings` table, migrations `2026_07_09_000012` + `…000013` + `2026_08_10_000009`
  which adds nullable **`quote_raw`** — the verbatim FareQuote response Book echoes back — plus
  `…000010` **`seats_available`** and `…000011` **`result_type`**, the two search-only fields FareQuote
  drops and Book requires) —
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
  `GET /{booking}` (show, `can:booking.view`), and the **money step**:
  `POST /{booking}/book` (**`flight.book` AND `flight.issue`** — a hold nobody may ticket just
  occupies airline seats) and `POST /{booking}/issue` (`can:flight.issue`).
  Both re-check **ownership on the write**, not just the read, so an id cannot be posted for someone
  else's booking.
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
- **Ticketing lives on the booking detail page**, not at the end of the wizard — a quoted booking is
  reviewed and then issued as a deliberate second act, rather than money moving as a side effect of
  finishing a form. The panel offers **Hold PNR** (non-LCC only) and **Issue ticket**, each behind its
  own permission, and a **LIVE** booking gets a red warning banner and a red button.
- **Payment** is no longer a bare stub: it shows the agency **wallet balance** and *remaining after
  booking*, reddens a shortfall, blocks **Complete booking** and offers *Request a load*. It is
  advisory — the server re-checks under lock at submit. There is still **no card/gateway step**, and no
  TBO commitment happens here. See [`../wallet/00-overview.md`](../wallet/00-overview.md).

## Console commands (`app/Console/Commands/`)

`tboair:auth {--fresh}`, `tboair:balance {--fresh}` (our TBO balance, with the e-wallet distinction
spelled out in the output), **`tboair:payload {booking} {--ticket} {--pnr=} {--json}`** — dry-runs the
Book/Ticket request for a booking and pre-flight-checks it (**sends nothing**; built so each
certification case can be inspected before it costs anything),
`tboair:logs {id?} {--limit=} {--type=} {--failed}`,
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
`Unit/TboBookingStatusTest` (the ten codes, and the four that must refuse to map),
`Feature/TboAir/BookPayloadTest` (verbatim segments, restored seats, enum encoding, infant guards,
Book-vs-Ticket PNR), `Feature/TboAir/BookAndIssueTest` (LCC vs non-LCC, idempotency, the write lock,
reconciliation, unresolved outcomes, supplier-funds guard, **a failed Book never reaching Ticket**),
`Feature/TboAir/SeatAvailabilityTest`, `Feature/TboAir/TicketingRoutesTest` (both permissions,
ownership, LIVE flag, timeout wording), `Feature/TboAir/PayloadCommandTest`, and the admin
settings/logs tests.

**Fixtures worth knowing are real, not written from the docs:** `bookingdetails.json` is the response
for PNR `984XIX`, and `book-auth-failed.json` is a verbatim refused Book — both exist because reading
them from the documentation is what produced the bugs in the first place.
Balance fixtures: `balance.json` (flat, as documented) and
`balance-agency.json` (nested, as observed).

## Environment variables

`TBOAIR_ENV`, `TBOAIR_TEST_USERNAME`/`_PASSWORD`, `TBOAIR_LIVE_USERNAME`/`_PASSWORD`, `TBOAIR_IP_ADDRESS`,
`TBOAIR_AUTH_MODE`, `TBOAIR_BOOKING_MODE`, `TBOAIR_TOKEN_TTL`, `TBOAIR_CACHE_KEY`,
`TBOAIR_SEARCH_CACHE_TTL`, `TBOAIR_RECENT_TTL`, `TBOAIR_BALANCE_CACHE_TTL`, `TBOAIR_TIMEOUT`,
`TBOAIR_CONNECT_TIMEOUT`,
`TBOAIR_LOGGING`, and per-endpoint overrides `TBOAIR_AUTH_URL` / `TBOAIR_SEARCH_URL`. (Live credentials
are currently unset — live auth fails until provided.)

## Gaps for the booking lifecycle

Book, Ticket and GetBookingDetails now exist. What remains:

### What the first real Book proved — and what it cost

`MT-FBVMSJVR` → **PNR `984XIX`**. Getting there took five attempts, and each refusal was TBO
correcting its own documentation. All five fixes are in the code and covered by tests; the details
live in `01`§5.1 and §6.

| Attempt | TBO said | What was wrong |
| --- | --- | --- |
| 1 | *Authentication Failed* | The booking host expires tokens sooner than search **and reports it differently**; our re-auth never fired |
| 2 | *Passport Number and Passport Expiry should not be Empty* | Passport flags chained with `??`, hiding `IsPassportRequiredAtTicket` |
| 3 | *Passport number must contain only letters and numbers* | A hyphenated Philippine ID reached the passport field |
| 4 | *Invalid title. Parameter name: title* | `Title` sent as the documented ordinal instead of the word |
| 5 | **PNR `984XIX`** | — |

**`Fare_BE` is now validated.** The open question was whether to send the whole itinerary `Fare`
object per passenger (as the live system does) or the per-passenger split TBO documents. The Book that
succeeded sent the whole object, so the live system's approach is right and the doc is not.

**Also validated by that booking:** `quote_raw` as the payload source, `seats_available` and
`result_type` carried from search, the contact fan-out, the lead-pax rule, and `TboBookPayload`'s
`IdDetails` block.

**Still unproven:** the **Ticket** call itself, and everything in Phase 5.

### Domestic round-trip is not split into two PNRs

TBO returns **two result indexes** for a domestic round trip — outbound and inbound — and the whole
chain is meant to run OB first, then IB, producing **two separate PNRs** (`01`§3). We build a single
payload from one `ResultIndex` and derive `SearchType` from the segments' `TripIndicator`, so a
domestic return would go up as one booking. International returns are unaffected.

This has not been hit because nothing has been booked yet. It needs deciding before certification —
cases 3, 5, 8 and 9 are returns.

### The Ticket call is still unproven

Book works; `issue()` has never been sent. It is the same payload with the held PNR, so the shape is
already validated — but the response envelope, its status codes and any further business rules are
unknown, exactly as Book's were before the first attempt. Expect at least one surprise.

There is also a **live PNR (`984XIX`) held on test** with `LastTicketDate 2026-09-07T20:00`. We have
no ReleasePNR, so releasing it means contacting TBO.

### Still to build (Phase 5)

**ReleasePNR, Void, Refund** — the config entries are dormant and, for refund, probably on the wrong
controller (see above). `GetLastTicketDate` is unimplemented, so a held PNR's ticketing deadline is not
enforced. Per-passenger `TicketId`/`TicketNumber` are not yet read out of GetBookingDetails and stored,
and ReleasePNR needs them. Seat-map selection remains deferred.

See `03-implementation-plan.md`.

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
- **Title is constrained to `Mr` / `Mrs` / `Miss`** — the only three TBO accepts. The wizard used to
  offer `Ms` and `Mstr`, which have none. It is sent as the **word**, not the ordinal TBO documents.
- **The identity document follows the route, not TBO's flags** — a passport internationally, any
  government ID domestically (`FareQuote::$isDomestic`, derived from the segments' country codes
  against `config('tboair.point_of_sale')`). `Passenger` carries one typed document (number, expiry,
  issuing country, issue date); the wizard relabels between "Passport no." and "ID number". See
  `04`§5 for the model and `01`§5.1 for what goes on the wire.
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
  token. It is **not** consulted before ticketing: TBO reports insufficient funds on the Book/Ticket response
  itself, which is authoritative, and a cached second opinion could only disagree with it. The balance
  is an **ops read**, surfaced in admin and on the CLI.
- **Ops surface:** a *Check now* panel on admin Settings (`supplier.tbo.view`), audit event
  `tbo.balance_checked`, and `php artisan tboair:balance {--fresh}`.
- **Read on demand, never on page render** — the call mints a TokenId, and TBO's guide still claims
  one token per day, so polling risks churning the token an in-flight booking is using.

Also fixed while here: `TboAirService::firstError()` only looked for **nested** `Error` objects, so the
**flat** `ErrorMessage` these credential calls use was dropped, and a present-but-empty message beat
the fallback and rendered a blank error.

### 4. Session handling vs the live system — one real gap

Comparing against the production implementation at `b2b.philippineexplorer.com`
([`04-live-reference-implementation.md`](04-live-reference-implementation.md) §7) raised two
differences. Checking them against our own `tbo_air_api_logs` cleared one and confirmed the other.

**✅ Not a gap — the stale-session signal.** The live system retries on `ResponseStatus == 4` where we
key on `Error.ErrorCode == 6`. TBO sends **both on the same response**, so either detects it. The one
real expiry we have logged (`#214`) carried `ResponseStatus: 4` *and* `ErrorCode: 6` "Invalid Token",
and `withReauth()` handled it unaided — `#215` re-authenticated three seconds later and `#216` retried
successfully. See `01`§6 for the payload and the full `ResponseStatus`/`ErrorCode` pairing table.

**⚠️ Real — token refresh has no lock.** We use a bare `Cache::remember()`, so every concurrent miss
can fire its own Authenticate. The live system wraps refresh in `Cache::lock('tboair-auth-lock')` with
waiters blocking for the winner's token. It has not bitten us — one expiry in 338 calls — but it
matters more if TBO's "one token per day" is ever real. Cheap to close.

Their `token_ttl` is also **12h** against our 23h — see `01`§4 for the four conflicting figures.
