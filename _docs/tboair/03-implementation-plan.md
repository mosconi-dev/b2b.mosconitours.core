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
`isLcc`. So the data needed for FareRule/FareQuote is already surfaced.

**The API-generation check carried through Phases 1–3 unresolved, and now has a concrete shape.**
`fare_rule`/`fare_quote`/`ssr` proved fine in practice (they share the search host and the
`TraceId`/`ResultIndex` naming). **Book/Ticket do not**: they sit on a different host and route style
and their doc pages name the identifiers `ResultId`/`TrackingId`. This is now a **Phase 4 blocker** —
see §4 of `01-tbo-api-reference.md`.

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

## Phase 4 — Book & Ticket (the money step) — DONE (untested against TBO)

**Goal:** issue tickets. Branch on `IsLCC` from FareQuote.

### Phase 4.0 — Prerequisites (do these before writing `book()`)

Reading TBO's Book/Ticket method pages (§5 of `01-tbo-api-reference.md`) turned up four items that
change the schema or the payload shape. Settle them first; retrofitting them mid-Phase-4 is worse.

| # | Item | Action |
| --- | --- | --- |
| ~~**P1**~~ | ~~Identifier naming/generation.~~ | ✅ **DONE — resolved without TBO.** The live production system stores the search's `TraceId`/`ResultIndex` and submits them to Book as `TrackingId`/`ResultId`, from the same mixed hosts our config uses. See [`04-live-reference-implementation.md`](04-live-reference-implementation.md) §1. |
| ~~**P2**~~ | ~~Book echoes the whole itinerary, and our stored quote is a lossy UI transform.~~ | ✅ **DONE** — nullable `bookings.quote_raw` json column (migration `2026_08_10_000009`) holds the FareQuote response verbatim; `FareQuote::$raw` carries it and is kept out of `toArray()` so it never reaches the browser. |
| ~~**P3**~~ | ~~`Passenger` lacks ~8 mandatory Book fields; TBO wants integer enums where we store strings.~~ | ✅ **DONE** — address/mobile/email collected once as contact and **fanned onto every pax row**; `isLeadPax` per passenger (exactly one, adult only); Title constrained to `Mr/Mrs/Miss`; `TboPassengerMapper` does the string→ordinal encoding. |
| ~~**P4**~~ | ~~TBO's own agency balance is invisible.~~ | ✅ **DONE** — `agency_balance` endpoint (**URL verified live**), `AgencyBalance` DTO, `TboAirService::agencyBalance()` / `hasFundsFor()` (the pre-Ticket seam for 4.1), `tboair:balance`, and a **Check now** panel on admin Settings. |

Each is detailed under "Gaps for the booking lifecycle" in `02-current-implementation.md`.

### Phase 4.1 — The calls (DONE)

> **Shipped:** `TboBookingStatus` (TBO's ten codes; the four ambiguous ones refuse to map),
> `BookingResult` + `TboAirService::bookingDetails()`, the two search-only fields carried to the
> booking (`seats_available`, `result_type`), **`TboBookPayload`** (one builder — Ticket is Book plus a
> PNR), `BookingService::book()`/`issue()` with the write lock, environment/status/PNR/supplier-funds
> guards and GetBookingDetails reconciliation, and `POST /bookings/{booking}/book` + `/issue` behind
> `flight.book` / `flight.issue` with a ticketing panel on the booking page (LIVE gets a red warning).
> Covered by `BookPayloadTest`, `BookAndIssueTest`, `TicketingRoutesTest`, `SeatAvailabilityTest`,
> `TboBookingStatusTest`.
>
> ⚠️ **No Book or Ticket call has ever been made** — all of it is `Http::fake`. The server needs TBO
> whitelisting first, and **`Fare_BE`** (whole fare object per passenger, mirroring production over the
> docs) is the assumption most worth checking. **Domestic round-trip two-PNR is not implemented** —
> decide before certification, since four of the 11 cases are returns. See `02`'s gaps section.

#### As originally planned

> **Start from the live implementation, not the doc pages.**
> [`04-live-reference-implementation.md`](04-live-reference-implementation.md) covers a working
> production Book/Ticket, including §5's list of its defects — which is as valuable as the working
> parts, because 4.1 will be tempted to mirror them.
>
> **One payload builder.** Ticket is the Book payload with a `PNR` field — `null` for LCC, the Book
> response's PNR for non-LCC. Do not build two request shapes (`04`§2).

- **TBO methods:** `Booking/Book` (non-LCC), `Booking/Ticket` (LCC = book+ticket; non-LCC = ticket a
  held PNR), `Booking/GetBookingDetails` (mandatory after each), `Booking/GetLastTicketDate`.
- **Build:**
  - `BookingService::book()` (non-LCC → PNR, status `booked`) and `issue()` (Ticket → status
    `ticketed`); both call `GetBookingDetails` and persist the authoritative result + PNR/BookingId.
  - **LCC path:** FareQuote → (SSR) → Ticket directly. **Non-LCC path:** Book → Ticket.
  - **Domestic round-trip:** run the chain **OB first, then IB** → two PNRs on the one booking.
  - **Idempotency:** a DB lock / unique guard so one `Booking` can never be ticketed twice; wrap in a
    transaction; on ambiguous failure, reconcile via `GetBookingDetails` before any retry.
  - **Status mapping.** TBO returns a **10-value** enum (`NotSet=0 … Cancelled=9`) against our six
    `BookingStatus` cases. `Pending=7`, `InProgress=8` and `NotConfirmed=6` are **not** failures and
    **not** successes — they must resolve via `GetBookingDetails` before we decide or retry anything.
    Certification **Case 11 tests `InProgress` explicitly**, so this path is required, not optional.
  - **Persist the PNR first, then the TicketIds.** GetBookingDetails is keyed on **PNR** (not
    BookingId), so the PNR is the reconciliation key — write it the moment Book returns, before
    anything else can fail. Store the per-passenger `TicketId`/`TicketNumber` onto the `pax` json:
    ReleasePNR needs **all** TicketIds for a full cancellation (Phase 5).
  - **Payment:** TBO has no gateway — collect payment first (our side), then Ticket deducts the **TBO**
    agency balance. Record the payment reference on the booking. Note the internal wallet is already
    debited at **quote** time (`createFromQuote`), so the money moves before the TBO commitment does;
    `transitionTo(failed)` refunds it. Ticketing therefore has **two** balances that can each say no —
    see P4.
  - Routes `POST /bookings/{booking}/book`, `/issue`, gated by `can:flight.book` / `can:flight.issue`;
    LIVE booking shows the existing red LIVE guard.
- **Four things the live system gets wrong — build these right** (`04`§5):
  1. **A failed Book must abort.** There, it sets a failed status and falls through to Ticket with a
     null PNR, which silently takes the LCC path and tries to issue an unbooked itinerary.
  2. **Call `GetBookingDetails` in the pipeline**, not only from an operator screen. It is the only
     authoritative status read and the only way to reconcile an ambiguous outcome.
  3. **Guard idempotency.** Nothing there stops one transaction ticketing twice.
  4. **Never write the wallet from a stale snapshot.** Ours already debits under lock against an
     authoritative ledger — keep it that way.
- **Tests:** LCC vs non-LCC branching, OB-then-IB ordering, double-ticket prevention, failure →
  reconcile via GetBookingDetails, **a failed Book never reaches Ticket**, **`InProgress`/`Pending`
  resolve rather than fail**, env-consistency enforced across the whole chain, and **insufficient TBO
  balance surfaces distinctly** from insufficient wallet balance.

---

## Phase 5 — Post-booking management

**Goal:** view, cancel, void, refund.

- **TBO methods:** `Booking/GetBookingDetails`, `Booking/GetAllBookingDetailsByPnr`,
  `Booking/ReleasePNR`, `Queues/GetVoidAmountDetails` + `VoidRequest`, `Queues/RefundRequest` +
  `RefundApi`, `Wallet/GetAvailableBalance`.
- **Fix the dormant endpoint config first.** `refund` is configured under `Booking/RefundApi` but TBO
  documents Refund **and** Void under the **`Queues`** controller; `refund` is also **live-only** (so it
  cannot be exercised on test); and `GetVoidAmountDetails`, `VoidRequest`, `GetLastTicketDate` and
  `GetAvailableBalance` have no config keys at all. Table in `02-current-implementation.md`.
- **Build:** bookings list + detail page; **ReleasePNR** for unticketed holds (respect
  `GetLastTicketDate`); **Void** (same-day) and **Refund** flows with amount preview; surface agency
  **balance** in admin. Gate with `booking.cancel` / `booking.refund`.
- **ReleasePNR specifics:** the request needs `PNR` + **`LastName`** + `Remarks`, and **all `TicketId`s
  must be sent for a full cancellation** — so Phase 4 must already have stored them. It also supports
  **partial** cancellation (per passenger or per sector), which the UI should either expose or
  explicitly not offer.
- **Two balances, not one.** "Agency balance" in admin means **TBO's** (`Wallet/GetAvailableBalance`) —
  distinct from the internal e-wallet at `/wallet`. Label them unambiguously or they will be confused.
- **Refund vs our wallet:** `transitionTo` refunds the internal charge **in full** on
  cancelled/refunded, but TBO applies real airline penalties. Until fees are modelled the difference
  must be posted as a manual `wallet.adjust` — see `_docs/wallet/00-overview.md`.
- **Tests:** cancel only-when-allowed, void window, refund amount preview, balance render.

---

## Phase 6 — Certification & go-live

**Goal:** pass TBO certification and switch to production.

- Run the **11 required test cases** on the **test** environment — the exact matrix (5 LCC, 4 non-LCC,
  plus price/schedule-change and `InProgress` handling) is tabulated in §7 of
  `01-tbo-api-reference.md`. Two shape the build rather than merely exercising it: **Case 10**
  (price/schedule change on non-LCC → `IsPriceChanged` / `IsTimeChanged`) and **Case 11**
  (`InProgress`). Cases 4, 5, 7 and 9 require **baggage/meal**, so Phase 3's SSR work is on the
  certification path.
- Submit the JSON request/response **plus PNR numbers**, **case by case, not consolidated**;
  `GetBookingDetails` must appear in every case. The **API Logs** pages already capture the evidence —
  an export-per-case view would make this mechanical.
- Verification takes **4–5 working days**. If a PNR generates but Ticket errors supplier-side, send the
  logs — support assesses those rather than failing the case.
- **Order matters at the end:** sign-off → live credentials → **then** submit the static public IP for
  whitelisting. Set `TBOAIR_LIVE_USERNAME` / `TBOAIR_LIVE_PASSWORD`, do a single controlled live
  booking behind the LIVE guard, then enable live for the intended users (`supplier.tbo.live`).

---

## Suggested delivery order & sizing

| Phase | Rough size | Ships value |
| --- | --- | --- |
| ~~1 — FareRule + FareQuote~~ | S–M | ✅ done |
| ~~2 — Booking domain~~ | M | ✅ done |
| ~~3 — SSR~~ | M | ✅ done |
| **4.0 — Prerequisites** | **S–M** | Unblocks 4.1; **one is a schema change**, one is a question to TBO |
| **4.1 — Book + Ticket** | **L** | Actual ticketing (money) |
| 5 — Management | M | Cancel / void / refund |
| 6 — Certification | S–M (mostly process) | Production go-live |

Phases 1–3 shipped in that order, so the remaining path is simply **4.0 → 4.1 → 5 → 6**.

**4.0 is complete — 4.1 is unblocked.** P1 was expected to need TBO and a turnaround; reading the
live production system settled it instead, along with the shape of the Book/Ticket payload itself.

Two questions remain genuinely open, but **neither blocks 4.1**: the **token validity** figure (12h /
20h / 24h / 23h — see `01`§4) and whether refund is **`Booking/RefundApi` or `Queues/RefundApi`**
(Phase 5, and unverified in the live system too). Fold them into a TBO email whenever convenient.

One small fix worth picking up alongside 4.1 (`04`§6.2): **token refresh has no lock**, so concurrent
cache misses can each fire an Authenticate. The live system wraps it in a `Cache::lock`.

(The re-auth *signal* was briefly suspect — production keys on `ResponseStatus == 4`, we key on
`ErrorCode 6` — but TBO sends both on the same response and our self-heal is confirmed working in the
logs. Nothing to do. See `02`§4.)
