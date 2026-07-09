# TBO Air — Phase-by-Phase Implementation Plan

Goal: grow the existing **search** integration into a full **search → price → book → ticket →
manage** flight lifecycle, safely and in tested increments. Each phase is independently shippable
and testable (SQLite in tests, MySQL in dev/prod), and lands as its own commit on `master`.

See `01-tbo-api-reference.md` for the TBO API surface and `02-current-implementation.md` for what
exists today.

---

## Cross-cutting rules (apply to every phase)

1. **One environment end-to-end.** A booking is stamped with the environment it started in
   (`test`/`live`) and **every** downstream call (FareRule → FareQuote → SSR → Book → Ticket →
   management) must use that same environment. Never search on `test` and book on `live`. Enforce it
   at the persistence + service boundary, not just the UI.
2. **Respect the ~15-minute TraceId window.** FareQuote/Book must run against a *fresh* search. Do not
   reuse a `TraceId` older than the window — re-search and re-price instead. (Our result cache TTL
   must stay under this window when results feed booking.)
3. **Money-safe by default.** Ticketing spends real agency funds. Guard every Book/Ticket with:
   idempotency (no double-issue for one booking), an explicit re-price confirmation (FareQuote price
   may differ from Search), and a persisted state machine so a retry never re-charges.
4. **Log everything.** Every TBO call already flows through `TboAirApiLog` (request/response, masked
   secrets, env, user). Keep new calls on that path — the logs are also the certification evidence.
5. **RBAC-gate every action.** Reuse the registry: `flight.search` (done), `flight.book`,
   `flight.issue`, and the `booking.*` module for management/cancel/refund. Add actions to
   `config/rbac.php` as each phase needs them; gate routes with `can:...`.
6. **`GetBookingDetails` is the source of truth.** After any state-changing call, read it back and
   persist the authoritative status rather than trusting the write call's response alone.

---

## Phase 0 — Foundation (DONE)

Already in `master`:

- Authenticate + **Search** (`TboAirService::search`), token caching (~24h) with `ErrorCode 6`
  self-healing re-auth.
- **Test/live environment switching** — global + per-user, permission-gated, everything namespaced
  by environment (token cache, result cache, API-log rows).
- **Result caching** (`FlightSearchCache`), **result transform** to UI offers, **API logging**
  (`tbo_air_api_logs` + global and per-user log pages), **admin session controls** (TTL, token
  seed/flush).

**Confirmed for Phase 1:** the result cache TTL is **300s (5 min)** — safely inside the 15-min TraceId
window — search returns a top-level `TraceId`, and each `FlightOffer` already carries `resultIndex` +
`isLcc`. So the data needed for FareRule/FareQuote is already surfaced. The one open item is the
**API-generation check** (see `02-current-implementation.md`): confirm the dormant `fare_rule` /
`fare_quote` config URLs and their field names are the current TBO generation before wiring them.

---

## Phase 1 — Fare pipeline: FareRule + FareQuote (DONE)

> **Shipped:** `SelectionInput` / `FareQuote` / `FareRule` DTOs, `TboAirService::fareQuote()` /
> `fareRule()` on a shared `withReauth()` (self-heals on ErrorCode 6, reused by search), client
> `fareQuote()`/`fareRule()` calls, `POST /flights/fare-quote` + `/flights/fare-rule`
> (`can:flight.search`, not cached), and the **Select → Confirm-fare modal** (re-price, price-changed
> notice, LCC / refundable / passport badges, per-pax breakdown, on-demand fare rules; "Continue to
> booking" stubbed for Phase 4). Tested by `FarePipelineTest` + a flights-page render guard.

**Goal:** when a user selects an offer, fetch its rules and a binding re-price before any commitment.

- **TBO methods:** `Detail/FareRule`, `Detail/FareQuote`.
- **Build:**
  - `TboAirService::fareRule(SelectionInput)` and `fareQuote(SelectionInput)` (carry `TraceId` +
    `ResultIndex`, same env/token path as search).
  - DTOs: `FareQuote` (final `BaseFare`, `Tax`, `PublishedFare`, `IsLCC`, `IsPassportMandatory`,
    fare-breakup, per-pax split) and `FareRule` (rule text / cancellation policy).
  - `FlightController` endpoints (`POST /flights/fare-rule`, `POST /flights/fare-quote`) gated by
    `can:flight.search`; UI shows rules + the (possibly changed) price with a "price changed" notice.
- **Rules:** surface `IsLCC` (drives Book-vs-Ticket later) and passport-required. Handle a stale
  `TraceId` by prompting a re-search.
- **Tests:** `Http::fake` FareRule/FareQuote fixtures; assert re-price parsing, per-pax split,
  `IsLCC` flag, and stale-TraceId handling. Add fixtures under `tests/Fixtures/tboair/`.

---

## Phase 2 — Booking domain + passengers (DONE)

> **Shipped:** `bookings` table (env stamped + immutable via a model guard; `result_index` as `text`),
> `BookingStatus` enum with a guarded state machine, `Booking` model + factory, `Passenger` DTO,
> `BookingService::createFromQuote()` (re-prices via FareQuote — a read — and persists a `quoted`
> booking; enforces passport when the fare requires it) + `transitionTo()`, `booking` RBAC module
> enabled with a Bookings nav item, `StoreBookingRequest`, `POST /bookings` + read-only
> `GET /bookings` & `/bookings/{booking}` (own-bookings only), and the **passenger-entry UI** — the
> confirm-fare modal's "Continue to booking" (gated by `booking.create`) opens a dynamic passenger
> form (rows built from the quote's fare breakdown; passport fields appear when the fare requires
> them) that POSTs to `/bookings` (which content-negotiates JSON for the XHR) and redirects to the
> booking. Tested by `BookingTest` + `BookingStatusTest`.

**Goal:** a durable booking record to hang the write-steps off, so retries are safe.

- **Build:**
  - Migration `bookings`: `id`, `reference` (our own), `user_id`, **`environment`**, `status`
    (enum-ish string: `quoted|booked|ticketed|failed|cancelled|refunded`), `trace_id`,
    `result_index` (**`text` — TBO ResultIndex tokens far exceed 255 chars**), `is_lcc`,
    `pnr` (nullable), `booking_id` (TBO's, nullable), pricing snapshot
    (`json`), `pax` (`json`), timestamps, soft deletes. Portable types.
  - `Booking` model + relations; a `Passenger` value object / request (`Store` FormRequest) with
    title/first/last/DOB/gender/passport (conditional on `IsPassportMandatory`), contact.
  - `BookingService` scaffold that persists a `quoted` booking from a FareQuote result (no TBO call
    yet) and owns the **state machine** + env stamping.
  - `booking` module in `config/rbac.php` (`view`, `create`, `cancel`, `refund`), routes under
    `/bookings`.
- **Tests:** state transitions, env is stamped and immutable, passport required only when the quote
  says so, guards against illegal transitions.

---

## Phase 3 — Ancillaries: SSR (baggage + meal) — DONE

> **Shipped:** `Ssr` DTO (flattens TBO's per-segment `Baggage` / `MealDynamic`), `TboAirService::ssr()`
> + client `ssr()` on the shared `withReauth()`, `POST /flights/ssr` (`can:flight.search`).
> `BookingService::applyAncillaries()` re-fetches **GetSSR** to price selections **authoritatively**
> (never client prices), **forbids extra baggage for infants**, stores each pick on the pax row, and
> folds the spend into `ancillary_total` + `total_amount`. UI: the passenger form (LCC only) shows a
> baggage + meal dropdown per passenger with a live running total. Tested by `FarePipelineTest` (SSR
> endpoint) + `BookingTest` (ancillary fold-in, infant guard). **Deferred:** seat-map selection
> (a large grid UI) — baggage + meal cover the main ancillary revenue and prove the SSR pipeline.

**Goal:** let users add baggage/meal/seat where available before ticketing.

- **TBO methods:** `Detail/GetSSR` (+ `GetFreeMeals`/`AddOnFares` if needed).
- **Build:** `TboAirService::ssr(SelectionInput)`, DTOs for baggage/meal/seat options, selection UI,
  persist choices onto the `Booking` (`json`).
- **Rules:** **arrays never null** (empty arrays instead); **no baggage/seat for infants**; **re-run
  GetSSR as the last detail call before Ticket** if the selection changed; add SSR cost into the
  price snapshot.
- **Tests:** infant restriction enforced, empty-vs-null arrays, SSR price folds into totals.

---

## Phase 4 — Book & Ticket (the money step)

**Goal:** issue tickets. Branch on `IsLCC` from FareQuote.

- **TBO methods:** `Booking/Book` (non-LCC), `Booking/Ticket` (LCC = book+ticket; non-LCC = ticket a
  held PNR), `Booking/GetBookingDetails` (mandatory after each), `Booking/GetLastTicketDate`.
- **Build:**
  - `BookingService::book()` (non-LCC → PNR, status `booked`) and `issue()` (Ticket → status
    `ticketed`); both call `GetBookingDetails` and persist the authoritative result + PNR/BookingId.
  - **LCC path:** FareQuote → (SSR) → Ticket directly. **Non-LCC path:** Book → Ticket.
  - **Domestic round-trip:** run the chain **OB first, then IB** → two PNRs on the one booking.
  - **Idempotency:** a DB lock / unique guard so one `Booking` can never be ticketed twice; wrap in a
    transaction; on ambiguous failure, reconcile via `GetBookingDetails` before any retry.
  - **Payment:** TBO has no gateway — collect payment first (our side), then Ticket deducts agency
    wallet. Record the payment reference on the booking.
  - Routes `POST /bookings/{booking}/book`, `/issue`, gated by `can:flight.book` / `can:flight.issue`;
    LIVE booking shows the existing red LIVE guard.
- **Tests:** LCC vs non-LCC branching, OB-then-IB ordering, double-ticket prevention, failure →
  reconcile via GetBookingDetails, env-consistency enforced across the whole chain.

---

## Phase 5 — Post-booking management

**Goal:** view, cancel, void, refund.

- **TBO methods:** `Booking/GetBookingDetails`, `Booking/GetAllBookingDetailsByPnr`,
  `Booking/ReleasePNR`, `Queues/GetVoidAmountDetails` + `VoidRequest`, `Queues/RefundRequest` +
  `RefundApi`, `Wallet/GetAvailableBalance`.
- **Build:** bookings list + detail page; **ReleasePNR** for unticketed holds (respect
  `GetLastTicketDate`); **Void** (same-day) and **Refund** flows with amount preview; surface agency
  **balance** in admin. Gate with `booking.cancel` / `booking.refund`.
- **Tests:** cancel only-when-allowed, void window, refund amount preview, balance render.

---

## Phase 6 — Certification & go-live

**Goal:** pass TBO certification and switch to production.

- Run the **~11 required test cases** (1 adult; multi-adult; +child; +infant; +child+infant; one-way;
  return; domestic; international; non-stop; 1-stop; LCC; non-LCC; full book→ticket→cancel) on the
  **test** environment. The **API Logs** pages already capture the JSON request/response evidence to
  submit.
- Set `TBOAIR_LIVE_USERNAME` / `TBOAIR_LIVE_PASSWORD`; confirm the production server's egress IP is
  whitelisted with TBO; do a single controlled live booking behind the LIVE guard; then enable live
  for the intended users (`supplier.tbo.live`).

---

## Suggested delivery order & sizing

| Phase | Rough size | Ships value |
| --- | --- | --- |
| 1 — FareRule + FareQuote | S–M | Accurate price + rules before commit |
| 2 — Booking domain | M | Durable, retry-safe foundation |
| 3 — SSR | M | Ancillary revenue (LCC) |
| 4 — Book + Ticket | L | Actual ticketing (money) |
| 5 — Management | M | Cancel / void / refund |
| 6 — Certification | S (mostly process) | Production go-live |

Do **1 → 2 → 4** first for a minimal end-to-end (search → price → book/ticket), then **3** and **5**
to round it out, and **6** to go live.
