# TBO Hotel — Phase-by-Phase Implementation Plan

Goal: add **TBO Holidays hotels** as this platform's second transactional product, reaching a full
**catalogue → search → pre-book → book → voucher → manage** lifecycle. Each phase is independently
shippable and tested (SQLite in tests, MySQL in dev/prod) and lands as its own commit.

Read [`01-tbo-api-reference.md`](01-tbo-api-reference.md) for the supplier API and
[`02-live-reference-implementation.md`](02-live-reference-implementation.md) for how the system live
today does it — including the six defects this plan deliberately does not repeat.

The flight integration is the template for *shape*, not for *content*: `App\Services\TboAir` and
`App\Services\Booking` already solve environment resolution, per-user caching, API logging, wallet
debiting under lock, guarded state transitions and permission gating. Hotels reuse those seams and
add nothing parallel to them.

---

## Cross-cutting rules

1. **One environment end-to-end.** A booking is stamped `test`/`live` at creation and every
   downstream call uses that environment. Enforced at the service boundary, as
   `BookingService::guardEnvironment()` already does for flights.
2. **The 30-minute window is the clock, and `BookingCode` is what expires.** Search result caches
   stay well under it (10 min), the wizard shows the remaining time, and `Status.Code 315` renders
   as *"prices have expired, search again"* — never as a failure.
3. **PreBook is binding.** The price we charge, the cancellation policy we store, the supplements we
   display and the rate conditions on the voucher all come from the **PreBook** response
   (`01`§5). Search prices are indicative only.
4. **Money-safe by default.** No supplier call inside `DB::transaction`. Every write to one booking
   is serialised by a cache lock. The wallet is debited under lock against the ledger, never from a
   snapshot. `BookingReferenceId` is our own durable booking reference, generated before the call,
   so any outcome is reconcilable.
5. **Ambiguity is a state, not a failure.** A timed-out or unreadable Book leaves the booking
   `processing` and schedules the mandatory 120-second `BookingDetail` read (`01`§8). We never guess,
   and we never retry a Book we cannot prove did not happen.
6. **`BookingDetail` is the source of truth** after any state-changing call.
7. **Log every call** through the shared supplier log, so hotels appear in the same API-log pages as
   flights.
8. **RBAC-gate every action.** The `hotel` module already exists in `config/rbac.php` as a stub
   (`view`, `search`, `book`, `route => null`).
9. **Never hardcode `GuestNationality`** — §18 of the spec makes TBO's liability position explicit.
10. **Show `AtProperty` supplements before the booking step.** They are money the guest pays at the
    desk and must not be a surprise.

## Scope and non-goals

**In scope:** Search, PreBook, Book, Cancel, BookingDetail, the static catalogue methods, HCN
retrieval, the date-range reconciliation feed.

**Out of scope, deliberately:**

- **Card payments** (`PaymentMode: SavedCard | NewCard` and the whole `PaymentInfo` block). We book
  against TBO's credit limit, as production does. Adding cards means handling PAN/CVV — a
  compliance decision, not a feature.
- **Markup / selling price.** Core sells flights at the supplier fare today; there is no markup
  engine (`config/rbac.php` has `markup` and `markup.office` as stubs and nothing implements them).
  Hotels must **not** grow a private one — the live system's per-head hotel markup
  ([`02`](02-live-reference-implementation.md) §4.7) is the cautionary example. Design markup once,
  across products, in its own project.
- **Multi-currency.** TBO answers in the currency configured on our API profile. Guard that it
  matches the wallet currency and refuse loudly if it does not; conversion is out of scope.

---

## Architectural decisions

Nine decisions worth settling before any code, each with the reason it went that way.

### D1 — A parallel supplier namespace, not a shared abstraction

`App\Services\TboHotel\*` mirroring `App\Services\TboAir\*`. **No common `SupplierClient` interface
yet.** The two APIs share a vendor and almost nothing else: Basic Auth vs token sessions, `Status.Code`
vs `ResponseStatus`, one-step Book vs Book-then-Ticket. An abstraction drawn from two samples, one of
which is still growing, buys nothing and costs a layer. Extract it when a third supplier arrives and
the shape is evidence rather than a guess.

What *is* shared is genuinely cross-cutting: logging, environment resolution, the booking spine, the
wallet. Those are D2 and D3.

### D2 — Generalise the two supplier seams before writing hotel code

- **`tbo_air_api_logs` → `supplier_api_logs`** with a `supplier` column (`tboair` | `tbohotel`), model
  `SupplierApiLog`. Without it, hotel calls are invisible on the pages we use to debug and to evidence
  problems to TBO. The table is air-named only because it was written before there was a second
  supplier.
- **`TboEnvironmentResolver` → `SupplierEnvironmentResolver`**, taking a supplier key. Global settings
  become `tbo.environment` (air, unchanged) and `tbohotel.environment`; the per-user override column
  `users.tbo_environment` stays **one switch** — an agent testing is testing everything — gated per
  supplier by `supplier.tbo.live` / `supplier.tbohotel.live`.

Cheap now, disruptive after two suppliers are wired to the old names.

### D3 — One booking spine, one detail table per product

The `bookings` table is the money-bearing record: reference, user, agency, environment, status,
currency, totals, wallet linkage, state machine. All of that is product-neutral and already proven.
The flight-specific columns (`trace_id`, `result_index`, `is_lcc`, `pnr`, `seats_available`,
`result_type`) are not.

So: **keep one `bookings` table**, add a `product` discriminator, and hang a `hotel_bookings` detail
row off it.

```
bookings          + product ('flight'|'hotel', default 'flight', indexed)
                  + supplier ('tboair'|'tbohotel')
                  + supplier_reference  (PNR for flights, ConfirmationNumber for hotels; indexed)
                  ~ result_index made NULLABLE   ← currently NOT NULL text, blocks any non-flight row
hotel_bookings    1:1 detail — hotel identity, stay dates, rooms, policies, supplier ids
```

`quote` / `quote_raw` / `pax` are reused as-is and mean the same things: the priced snapshot, the
verbatim supplier response (here PreBook), and the traveller rows.

Why not two independent tables: one wallet path, one refund guard, one status machine, one
`/bookings` list, one policy. Why not fully normalise flights out of the spine at the same time:
that is churn on a path with a live PNR against it, and it can be done later without changing this
design. **The asymmetry is deliberate and temporary** — flight columns migrate to a
`flight_bookings` detail table when someone next has reason to touch them.

### D4 — Hotels need two more `BookingStatus` cases

`quoted → booked → ticketed` does not describe a hotel. Add:

- **`Confirmed`** — vouchered at TBO. The hotel terminal-success state; flights never reach it.
- **`Cancelling`** — TBO reports `CancellationInProgress` / `CancelPending` /
  `CxlRequestSentToHotel`. A cancellation is not instant and the intermediate state is real.

`Booked` and `Ticketed` stay flight-only. Transitions:
`Quoted → Processing | Confirmed | Failed | Cancelled`, `Processing → Confirmed | Failed | Cancelled`,
`Confirmed → Cancelling | Cancelled | Refunded`, `Cancelling → Cancelled | Confirmed` (a refused
cancellation returns the booking to confirmed). `isInFlight()` covers `Processing` and `Cancelling`.

A **`TboHotelBookingStatus`** enum owns the supplier's vocabulary — including the three spellings of
success in `01`§12 — and returns `null` rather than guessing for anything unrecognised, exactly as
`TboBookingStatus` does for air.

### D5 — The catalogue is ours, curated, and refreshed by a job

`Search` requires `HotelCodes` (`01`§4): there is no "search a city" call. A local catalogue is not an
optimisation, it is the precondition. But TBO's global inventory is far larger than anything we sell,
so:

- **Countries and cities: sync globally.** Small, and they drive autocomplete.
- **Hotels: sync per *enabled* city**, via `TBOHotelCodeList` (`01`§9), driven by a
  `hotel_sync_targets` table an admin manages. Start with the Philippines plus the outbound markets
  we actually book.
- **Upsert, never insert-if-missing.** Production's `continue`-on-exists means a renamed city keeps
  its old name forever ([`02`](02-live-reference-implementation.md) §3).
- **Resumable and chunked.** Production aborts the whole run on one failed city.

Models are named for the domain, not the vendor: `Hotel`, `HotelCity`, `HotelCountry`, `HotelImage`,
each carrying a `source` column (`tbo`) and the TBO code. This also avoids `App\Models\TboHotel`
colliding with the `App\Services\TboHotel` namespace — a clash production has to alias around on
every import (`use App\Models\TboHotel as ModelsTboHotel`).

### D6 — City search is chunked and concurrent, and says so when it is partial

~100 hotel codes per request means a 600-hotel city is six requests. Production sends
`limit(100)` with no ordering and shows an arbitrary sixth of the city with no indication
([`02`](02-live-reference-implementation.md) §4.6).

Instead: order the city's hotels deterministically (rating desc, then name) so **chunk 1 is the best
hundred**, issue chunks through a bounded `Http::pool`, render the first chunk immediately and fetch
the rest on demand. Cache per chunk under the search hash. Retry `429` with backoff, and when a chunk
fails after retries, **show the results we have and say how many properties could not be reached** —
a silent partial result is worse than a slow one.

`IsDetailedResponse: false` on the list search (§18's own recommendation, and the response is already
the largest in the flow); `true` on the single-hotel availability call when the agent opens a
property. That split is what the two-step UI wants anyway.

### D7 — PreBook is the gate, and a price move is a decision, not an error

The flight wizard already shows old-vs-new with a breakdown and asks the agent to accept or decline.
Hotels get the same treatment, over **all** rooms rather than `Rooms[0]`. PreBook's response is stored
verbatim in `quote_raw` and its `CancelPolicies`, `Supplements`, `RateConditions` and `Amenities`
replace the search-time copies everywhere.

### D8 — Our booking reference is the idempotency and reconciliation key

`bookings.reference` (`MT-XXXXXXXX`) is generated before Book and sent as **both**
`ClientReferenceId` and `BookingReferenceId`. It is the only handle `BookingDetail` accepts when no
`ConfirmationNumber` came back — the timeout case that §10 makes mandatory to handle. Production
sends a per-search session id instead, which is why an unconfirmed booking there needs a human.

### D9 — Two balances, and only one of them we can read

The agency e-wallet is debited at booking creation, under lock, by the existing `WalletService` —
same seam as flights. TBO's own credit limit has **no read endpoint in this spec**; insufficient funds
arrive as `Status.Code 300` on Book. So there is no pre-flight check to write: surface TBO's message
verbatim and distinguish it in the UI from a wallet shortfall.

On cancellation the guest is charged per the stored PreBook policy. **Refund the wallet net of the
computed charge**, show the agent that figure before they confirm, and mark the ledger entry as
estimated pending TBO's invoice — the Cancel response does not state the charge (`01`§7). Flights
currently refund in full and reconcile by hand; hotels can do better because the policy is
deterministic and date-based.

---

## Phase 0 — Shared foundations ✅ DONE

> **Shipped** (`59371bd`): `supplier_api_logs` + a `supplier` column and filter, `App\Enums\Supplier`,
> `SupplierEnvironmentResolver`, a `product`/`supplier`/`supplier_reference`-bearing booking spine with
> `result_index` nullable, `BookingStatus::Confirmed` and `Cancelling`, and the `hotel.cancel` +
> `supplier.tbohotel` RBAC modules. **One deviation:** `hotel`'s nav `route` stays `null` —
> `PermissionRegistry::navSections()` calls `route()` on any module that names one, so pointing it at
> a route that does not exist yet throws on every page render. Phase 3 sets it.

No user-visible change. Everything after this depends on it, and retrofitting it later touches every
file written in between.

- **Migration**: rename `tbo_air_api_logs` → `supplier_api_logs`, add `supplier` (default `tboair`,
  indexed). Rename the model to `SupplierApiLog`; update `TboAirClient`, `ApiLogController`,
  `UserController::logs`, the log views (add a supplier column + filter) and tests.
- **Migration**: `bookings` — add `product`, `supplier`, `supplier_reference`; make `result_index`
  nullable. Backfill existing rows to `flight`/`tboair` and copy `pnr` into `supplier_reference`.
- **`BookingStatus`**: add `Confirmed` and `Cancelling` with the transitions and badge colours from
  D4; extend `isInFlight()`.
- **`SupplierEnvironmentResolver`** (D2), keeping `TboEnvironmentResolver`'s behaviour for air.
- **`config/rbac.php`**: `hotel` gains `cancel` and `route => 'hotels'`; new `supplier.tbohotel`
  module (`view`, `sync`, `manage`, `live`) under the Suppliers group.
- **Tests**: the whole existing suite must pass unchanged apart from renames; add
  `BookingStatusTest` cases for the two new states and the illegal transitions between the flight and
  hotel branches.

## Phase 1 — Client and connectivity ✅ DONE

> **Shipped:** `config/tbohotel.php` (base URL per environment + a shared method map, per-method
> timeouts, throttle policy), `TboHotelConfig`, `TboHotelClient` (Basic Auth, per-method timeout,
> `429` retry that is **opt-in per call** so Book and Cancel can never auto-retry), `TboHotelStatus`,
> `TboHotelException`, `TboHotelService::countries()`/`cities()`, and `tbohotel:ping`.
>
> ✅ **The endpoint question is answered.** `tbohotel:ping --country=PH` against
> `https://api.tbotechnology.in/HotelAPI` returned **249 countries** (GET, 1197 ms) and **194
> Philippine cities** (POST, 1129 ms). The spec's URL is right and production's is stale. The
> credentials work, and the hotel API is **not IP-restricted** — the read side is developable
> locally, which TBO Air never was.

**Goal:** prove the credentials and settle the endpoint question before anything is built on top.

- **`config/tbohotel.php`** — `default` env, `environments.{test,live}` each with `credentials`
  (username/password) and `endpoints` (the eleven of `01`§2), **per-method timeouts** (search 23 s,
  prebook 23 s, book 120 s, static 60 s), `search_cache_ttl` (600), `booking_window` (1800),
  `logging`. Env vars `TBOHOTEL_ENV`, `TBOHOTEL_TEST_USERNAME`/`_PASSWORD`,
  `TBOHOTEL_LIVE_USERNAME`/`_PASSWORD`, plus per-endpoint URL overrides so the base-URL question can
  be answered without a deploy.
- **`TboHotelConfig::for(env)`**, **`TboHotelClient`** — Basic Auth, `Accept-Encoding: gzip, deflate,
  br`, per-method timeout, logs every call to `supplier_api_logs` with credentials masked, retries
  `429` with backoff. Bound per request in `AppServiceProvider` off the resolved environment, exactly
  as `TboAirClient` is.
- **`TboHotelStatus`** (the `Status.Code` table of `01`§3) and
  **`Exceptions\TboHotelException`** with `isNoAvailability()`, `isExpired()`, `isRateGone()`,
  `isInsufficientFunds()`, `isThrottled()`, `isTimeout()`.
- **`TboHotelService::countryList()` / `cityList(code)`** — the two cheapest calls, so connectivity is
  proven by real data.
- **`php artisan tbohotel:ping`** — hits CountryList on the chosen environment and prints the URL,
  status code, duration and row count. **This is the deliverable that answers `01`§13 Q1 and Q2.**
- **Tests:** `Http::fake` fixtures under `tests/Fixtures/tbohotel/`; assert Basic Auth is sent, the
  password is masked in the log row, each status code maps to the right exception, `429` is retried,
  and the log row lands with `supplier = tbohotel`.

## Phase 2 — Static catalogue and sync ✅ DONE

> **Shipped:** `hotel_countries` / `hotel_cities` / `hotels` / `hotel_sync_runs`, `CatalogueSyncService`,
> `tbohotel:sync {countries|cities|hotels|details}`, the queued `SyncHotelCatalogue` job, an admin
> catalogue page at `/admin/hotel-catalogue` (carry/drop a city, run a sync, read the last ten runs
> and what they skipped), and `GET /hotels/suggest`.
>
> **Loaded for real:** 249 countries, 194 Philippine cities, and 3,364 hotels across Manila (2,701)
> and Cebu City (663).
>
> **Three deviations from the plan below, all simplifications:**
> - **No `hotel_sync_targets` table.** `hotel_cities.is_enabled` says the same thing with one column.
> - **No `hotel_images` table.** Images are a json column on `hotels`; nothing queries them
>   individually, and the only read anyone needs is "the first one".
> - **`IsDetailedResponse` is not sent.** It is documented to add descriptions and images to
>   `TBOHotelCodeList` and measurably does nothing — the response is byte-identical either way — so
>   enrichment goes through `HotelDetails` instead, batched. See `01`§9.1.

**Goal:** a local, refreshable catalogue good enough to search a city properly.

- **Migrations:** `hotel_countries` (`code`, `name`, `source`), `hotel_cities` (`code`, `country_code`,
  `name`, `source`, `is_enabled`), `hotels` (`code`, `city_code`, `country_code`, `name`, `address`,
  `description`, `rating`, `map` lat/lng, `pin_code`, `phone`, `checkin_time`, `checkout_time`,
  `facilities` json, `attractions` json, `source`, `synced_at`), `hotel_images` (`hotel_code`, `url`,
  `position`), `hotel_sync_targets` (which cities we carry). Indexes on `city_code`, `(name)` for
  autocomplete, and unique `(source, code)` everywhere.
- **`CatalogueSyncService`** + **`php artisan tbohotel:sync {--countries} {--cities=} {--hotels=}
  {--since=}`** — countries → cities → per-enabled-city `TBOHotelCodeList` (detailed). **Upserts**,
  chunked, queued, resumable, and per-city failures are recorded and skipped rather than aborting the
  run. Writes a `hotel_sync_runs` row so admin can see what happened.
- **`HotelDetails` enrichment** (`--details`), including `IsRoomDetailRequired` for room-level
  images keyed by `RoomId`, handling `RoomID = 0` as "unmapped" (`01`§9).
- **Admin panel** under Settings → Suppliers, gated `supplier.tbohotel.sync`: enable/disable cities,
  run a sync, see the last run and its failures. Audit event `tbohotel.catalogue_synced`.
- **`GET /hotels/suggest`** — autocomplete over cities and hotels, gated `hotel.search`.
- **Tests:** upsert semantics (a renamed city updates), resume after a failed city, `RoomID = 0`,
  autocomplete ranking, and that a sync never blocks on a single 429.

## Phase 3 — Search ✅ DONE

> **Shipped:** `SearchInput`/`PaxRoom`/`HotelOffer`/`RoomOffer`/`SearchResult`, `CancelPolicySet` and
> `SupplementSet`, `TboHotelClient::searchPool()` (bounded concurrent chunks), `HotelSearchCache`,
> `GET /hotels` + `POST /hotels/search` + `GET /hotels/{code}`, and the results page with sort,
> filters and a property panel.
>
> **Proven live end to end:** Cebu City, 2 adults, 2 nights — 663 hotels in 7 chunks, ~5 s through
> the HTTP endpoint, 118 properties with availability, 0 chunks failed.
>
> **Two plan decisions reversed by measurement** (see `01`§4.1): chunk cost is nearly flat, so the
> whole city is searched rather than a ranked top-N — which would also have biased results upmarket,
> since price is unknown until TBO answers. And `IsDetailedResponse: true` costs +55% bytes but no
> measurable time, and is the only way to know at list time whether a rate is refundable.
> (`Supplements` come back either way — an earlier version of this note claimed otherwise.)
>
> **One thing added beyond the plan:** the property panel enriches a hotel on first open. Most of the
> catalogue has never been detailed, and crawling all of it for pages nobody visits is hours of calls
> — one call, once, when someone actually looks.

**Goal:** an agent can find real rooms for a real city and stay.

- **DTOs:** `SearchInput` (check-in/out, per-room `PaxRoom[]`, guest nationality, location type +
  code, filters), `HotelOffer` (hotel identity + cheapest room + badges), `RoomOffer` (booking code,
  names, meal type, refundable, transfers, total/tax, day rates, promotions, policies, supplements).
- **`CancelPolicySet` / `SupplementSet`** — small pure value objects doing the `Index`-bucketing,
  `'all'` fallback and `FromDate` sort that production gets right
  ([`02`](02-live-reference-implementation.md) §5), plus the defensive flattening `01`§12 requires.
- **`TboHotelService::search(SearchInput)`** — chunking, ordering, bounded concurrency, partial-result
  reporting (D6).
- **`HotelSearchCache`** — `hotel_search:{env}:{user}:{hash}:{chunk}`, 10-minute TTL, mirroring
  `FlightSearchCache`. **The TTL is a safety property here**, not a performance one: it must expire
  well inside the 30-minute booking window.
- **Routes** (`can:hotel.view` / `hotel.search`): `GET /hotels`, `POST /hotels/search`,
  `POST /hotels/more`, `POST /hotels/{code}/rooms` (the single-hotel detailed re-search).
- **UI:** search form (per-room occupancy, nationality, dates), results list with sort and filters
  (price, rating, refundable, meal, transfers), a property panel with images/facilities/map and its
  room list. Recent searches reuse `RecentSearchStore`'s pattern.
- **Tests:** chunk fan-out and ordering, a partial failure surfacing as partial, `201` rendering as
  "no availability" rather than an error, per-room occupancy mapping (including children's ages
  re-associated per room), cache key isolation per user and environment, double-nested supplements,
  policies with and without `Index`.

## Phase 4 — PreBook and the booking domain

**Goal:** a durable, priced, guest-complete booking record — with nothing yet committed at TBO.

- **Migration `hotel_bookings`:** `booking_id`, `hotel_code`, `hotel_name`, `city`, `country_code`,
  `check_in`, `check_out`, `nights`, `rooms_count`, `booking_code` (**`text`**), `guest_nationality`,
  `meal_type`, `is_refundable`, `with_transfers`, `cancel_policies` json, `supplements` json,
  `rate_conditions` json, `amenities` json, `confirmation_number`, `hotel_confirmation_number`,
  `invoice_number`, `hcn_attempts`, `hcn_next_attempt_at`, `cancellation_charge`, `cancelled_at`.
- **`TboHotelService::preBook(bookingCode)`** → `PreBookResult` carrying the authoritative price, the
  policy set, and `raw`.
- **`HotelBookingService::createFromQuote(user, selection, guests, contact)`** — re-prices via PreBook
  server-side (never trusting the client), compares **every** room's total, persists a `quoted`
  booking + its `hotel_bookings` detail, debits the wallet under lock, and stores PreBook's response
  in `quote_raw`. Mirrors `BookingService::createFromQuote()` and reuses its wallet and reference
  helpers.
- **`Guest` DTO** — `title` (**`Mr`/`Mrs`/`Ms`** — *not* the flight set), `firstName`, `lastName`,
  `type` (`Adult`/`Child`), `roomIndex`, `isLead`. Exactly one lead guest, an adult, in room 0.
  Contact block (email + country code + phone) sits on the booking, not per guest.
- **Wizard** at `/hotels/book` reusing `bookings/_stepper.blade.php`: Select Room → Guests → Review →
  Confirmation. The price-change gate appears only when PreBook moved the total. **`AtProperty`
  supplements are shown on Review**, itemised, labelled *payable at the hotel* and excluded from the
  charged total.
- **Tests:** price-change gate on room 2 of 3, expired `BookingCode` (`315`) redirecting to a fresh
  search, guest counts matching the searched occupancy, one-lead-adult enforcement, the wallet
  rolling back with the booking on a shortfall, PreBook's policies replacing the search-time set,
  `quote_raw` kept out of the JSON sent to the browser.

## Phase 5 — Book and reconciliation (the money step)

**Goal:** issue vouchers, and never lose a booking to a timeout.

- **`TboHotelBookPayload::for(Booking)`** — pure assembly from stored data: `BookingCode`,
  `CustomerDetails` grouped per room, `ClientReferenceId` and `BookingReferenceId` both set to
  `booking.reference`, `TotalFare` from **PreBook**, `BookingType: Voucher`, `PaymentMode: Limit`.
- **`HotelBookingService::book(Booking)`** — under `Cache::lock("booking:{id}:write")`, guarding
  environment and status, **outside any DB transaction**. On `200` + `ConfirmationNumber`: persist it
  to `supplier_reference` and the detail row *first*, then transition to `Confirmed`. On `300` /
  `405` / `207` / `315`: transition to `Failed` (refunding the wallet) and surface TBO's own message.
- **The ambiguous path**, which is the point of this phase: any timeout, transport error or
  unparseable response leaves the booking **`processing`** and dispatches
  **`ReconcileHotelBooking`** with a 120-second delay. That job calls `BookingDetail` by
  `BookingReferenceId`, and resolves to `Confirmed` or `Failed` only on an authoritative answer;
  anything else re-queues with backoff up to a cap and then raises for a human. **A booking is never
  re-Booked** — the reference is already spent.
- **Route** `POST /hotels/bookings/{booking}/book` gated `can:hotel.book`, LIVE showing the same red
  guard flights use. **`GET /hotels/bookings/{booking}/status`** for the wizard to follow a
  `processing` booking, reusing the flight status-poll pattern.
- **Voucher** — a print/PDF view rendered entirely from stored data (hotel, dates, rooms, guests,
  confirmation number, HCN when known, rate conditions, `AtProperty` supplements, cancellation
  policy), so a guest at a front desk never depends on TBO being reachable. Mirrors
  `bookings/eticket.blade.php`.
- **Tests:** a happy Book, each refusal code mapping correctly and distinctly (wallet shortfall vs
  TBO limit), a timeout producing `processing` + a queued reconcile, the reconcile resolving both
  ways, double-submit blocked by the lock, no supplier call inside a transaction, and the wallet
  refunded exactly once on failure.

## Phase 6 — Post-booking management

**Goal:** the operational half — knowing what TBO thinks, getting the hotel's own reference, and
cancelling with the right money.

- **Refresh** — `BookingDetail` by confirmation number from the booking page (`can:hotel.view`),
  persisting status, HCN, invoice number and per-room `Status` (the 24 Apr 2026 addition).
- **HCN retrieval** — `FetchHotelConfirmationNumber`, scheduled from the SLA table in `01`§8.1:
  first attempt at the SLA for the check-in window, then hourly, **max 3 retries**, then an ops
  flag on the booking. Skipped entirely when check-in is more than 30 days out, because TBO will not
  have one.
- **Cancel** — `TboHotelService::cancel(confirmationNumber)` behind `can:hotel.cancel`. Compute the
  charge from the stored PreBook policy **and show it before confirming**; on success move to
  `Cancelled` and refund the wallet **net** of the charge, posting the charge as its own ledger line
  marked estimated. `CancellationInProgress` and friends land in `Cancelling` and are resolved by the
  same reconcile job. `479` leaves the booking `Confirmed` with TBO's reason.
- **Ops reconciliation** — `php artisan tbohotel:bookings {--from=} {--to=}` over
  `BookingDetailsBasedOnDate` (`01`§10), reporting bookings TBO has that we do not, and vice versa.
  The safety net for anything the reconcile job could not settle.
- **Tests:** SLA windows choosing the right first attempt, the 3-retry cap, the >30-day skip, net
  refund arithmetic across fixed and percentage policies and across the policy date boundary, a
  refused cancel, and the drift report.

## Phase 7 — Go-live

- Live credentials into `TBOHOTEL_LIVE_USERNAME`/`_PASSWORD`; confirm the live BaseURL (`01`§13 Q2)
  and whether TBO requires IP whitelisting as the air side does.
- Ask TBO whether hotels have a **certification matrix** like air's 11 cases (`01`§13 Q6). If they do,
  the `supplier_api_logs` pages are already the evidence, as they were for flights.
- One controlled live booking behind the LIVE guard, then cancel it inside the free-cancellation
  window — verifying Book, BookingDetail, HCN and Cancel end to end on real money.
- Enable live per user via `supplier.tbohotel.live`.

---

## Sizing and order

| Phase | Size | Ships |
| --- | --- | --- |
| ~~0 — Shared foundations~~ | S–M | ✅ done |
| ~~1 — Client & connectivity~~ | S | ✅ done — endpoint confirmed by a real call |
| ~~2 — Catalogue & sync~~ | M–L | ✅ done — 3,364 PH hotels loaded |
| ~~3 — Search~~ | L | ✅ done — 118 Cebu properties in ~5s |
| 4 — PreBook & booking domain | M–L | A durable, priced, guest-complete booking |
| 5 — Book & reconciliation | L | Real vouchers, and no lost bookings |
| 6 — Post-booking | M | HCN, cancellation with correct money, ops reconciliation |
| 7 — Go-live | S (mostly process) | Production |

Strictly sequential: 0 → 1 → 2 → 3 → 4 → 5 → 6 → 7. Phase 1 can start the moment Phase 0's log rename
lands, and Phase 2's sync can run in the background while Phase 3 is built.

## Risks

| Risk | Mitigation |
| --- | --- |
| ~~**The test BaseURL in the spec and in production disagree**~~ (`01`§2) | ✅ closed — `tbohotel:ping` answered it: the spec's URL is correct, and the base stays env-overridable |
| **QPS limits bite on chunked city searches** | Bounded concurrency, `429` backoff, per-chunk caching, and ask TBO for the actual limit |
| ~~**Catalogue scale**~~ — TBO's global inventory dwarfs what we sell | ✅ handled — per-city `is_enabled` curation. Measured cost: Manila's 1,495-hotel list is 406 KB in 2 s, so the code list is cheap; only `HotelDetails` enrichment needs pacing |
| **The 30-minute window expires mid-wizard** | 10-minute result cache, a visible countdown, and `315` handled as "search again" rather than an error |
| **A Book times out after TBO created the booking** | `BookingReferenceId` = our reference, `processing` state, mandatory 120 s reconcile, never re-Book (D8, Phase 5) |
| **Cancellation charges are computed by us, not returned by TBO** | Show the figure before confirming, mark the ledger entry estimated, reconcile against TBO's invoice |
| **Spec contradictions** (`01`§12) | Parse defensively, refuse to map unknown enum values, and keep every fixture a **real** captured response — the flight fixtures earned that rule the hard way |
