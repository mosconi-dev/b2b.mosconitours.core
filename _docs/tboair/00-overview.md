# TBO Air Integration — Docs

Documentation for the **TBO Air** flight-supplier integration in `b2b.mosconitours.core`.

## Read in this order

1. **[01 — TBO API Reference](01-tbo-api-reference.md)** — the external TBO Air API: endpoints, the
   required booking workflow, token/TraceId rules, LCC vs non-LCC, error codes, certification.
2. **[02 — Current Implementation](02-current-implementation.md)** — what exists in our codebase
   today (Authenticate + Search + environment switching + logging), with file references.
3. **[03 — Implementation Plan](03-implementation-plan.md)** — the phase-by-phase plan to grow from
   search into the full **search → price → book → ticket → manage** lifecycle.

## TL;DR

- **Built:** the **search → price → book-as-quote** path — Authenticate, **Search**, **FareRule**,
  **FareQuote**, **SSR** — plus test/live **environment switching** (global + per-user, permission-gated),
  per-user/env result caching, per-user **recent-searches** cache, and full **API request/response
  logging** (global + per-user pages). The **"Select" action is live** and drives a full-page booking
  **wizard** that persists a `quoted` **Booking**.
- **Not built:** **Book, Ticket, GetBookingDetails, ReleasePNR, Refund** — no PNR is held and no ticket
  is issued (a Booking is only ever a priced quote). The endpoint URLs already exist (dormant) in
  `config/tboair.php`.
- **Two facts that shape the booking work:** the auth **token is valid ~24h** (TBO meeting — the
  published "12h" doc is stale; our 23h TTL is fine), and the **search `TraceId` is valid only ~15
  min**, so pricing/booking must run against a fresh search.
- **Done:** **Phase 1** (FareRule + FareQuote), **Phase 2** (booking domain + passenger UI), and
  **Phase 3** (SSR baggage + meal ancillaries, priced server-side and folded into the booking total).
- **Booking UX:** a **full-page wizard** at `/bookings/create` — Select Flight → Guest Details →
  Add-ons → Payment → Confirmation. Steps 1–3 are functional (reuse FareQuote / passengers / SSR);
  **Payment is a stub** and **Confirmation** shows the saved `quoted` booking — both become real with
  Phase 4 + a payment provider. **"Select" hands off straight to the wizard**, which does the
  **single** re-price (FareQuote); it shows a price-change gate (old vs new + breakdown; accept/decline)
  **only if the fare changed**, otherwise Guest Details directly. (No duplicate FareQuote on select.)
  Guest Details uses a left section-rail + contained form, and the search bar is **editable in place**
  (the real search form, reused from the flights page via an "embedded" mode); submitting it returns to
  Select Flight with the new search.
- **Recent searches** are real, not sample data: kept per-user in the cache (`RecentSearchStore`, ~1-day
  TTL), appended on each successful search (deduped, capped at 6), and click-to-refill.
- **Next step:** **Phase 4 (Book + Ticket)** — the money step (needs the whitelisted server for a real
  ticket). Seat-map selection is deferred. See `03-implementation-plan.md`.
- **Four things to settle before Phase 4 code** (from TBO's Book/Ticket method pages — Phase 4.0 in the
  plan, detail in `01`§5 and `02`'s gaps section):
  1. **Ask TBO whether `ResultId`/`TrackingId` are our `ResultIndex`/`TraceId`** — Book/Ticket sit on a
     different host and route style than search, and their docs use different names.
  2. ~~**Persist the raw FareQuote JSON.**~~ ✅ **done** — nullable `bookings.quote_raw` keeps the
     FareQuote response verbatim, since Book echoes the whole priced itinerary back.
  3. ~~**Extend `Passenger`.**~~ ✅ **done** — address/mobile/email collected once as contact and fanned
     onto every pax row, `isLeadPax` per passenger, Title limited to TBO's three, and
     `TboPassengerMapper` for the string→integer encoding.
  4. ~~**Read TBO's own agency balance.**~~ ✅ **done** — `agencyBalance()` / `hasFundsFor()`,
     `tboair:balance`, and a *Check now* panel in admin Settings. Ticketing draws down **our** TBO
     balance, not the internal e-wallet.

  **Only P1 is left, and it is the blocker for 4.1** — it is the one item we cannot answer ourselves.
- **Certification is 11 specific cases**, now tabulated in `01`§7 — 5 LCC, 4 non-LCC, plus a
  price/schedule-change case and an **`InProgress`** case. Four of them need baggage/meal, so Phase 3
  is on the certification path. Submit case by case with PNRs; 4–5 working days.

## Related in-app tooling

- Admin → **Settings**: switch global env, per-env token TTL + seed/flush, and **our TBO balance**
  (*Check now*, `supplier.tbo.view`) — distinct from the agency e-wallet at `/wallet`.
- Admin → **Users → Logs**: per-user API calls + activity.
- Console: `tboair:auth`, `tboair:balance`, `tboair:search`, `tboair:logs` (all live smoke tests
  require a TBO-whitelisted server).
- The `/admin` area and every `can:`-gated route/action above are governed by **RBAC** — see
  [`../rbac/00-overview.md`](../rbac/00-overview.md).
