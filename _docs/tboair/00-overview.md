# TBO Air Integration — Docs

Documentation for the **TBO Air** flight-supplier integration in `b2b.mosconitours.core`.

## Read in this order

1. **[01 — TBO API Reference](01-tbo-api-reference.md)** — the external TBO Air API: endpoints, the
   required booking workflow, token/TraceId rules, LCC vs non-LCC, error codes, certification.
2. **[02 — Current Implementation](02-current-implementation.md)** — what exists in our codebase
   today, with file references, and — just as important — **what is still untested against the real
   supplier**.
3. **[03 — Implementation Plan](03-implementation-plan.md)** — the phase-by-phase plan to grow from
   search into the full **search → price → book → ticket → manage** lifecycle.
4. **[04 — The Live Reference Implementation](04-live-reference-implementation.md)** — how
   `b2b.philippineexplorer.com`, the system live today, actually books and tickets. Better evidence
   than TBO's own docs, which it contradicts. **Read before changing Book/Ticket** — it also lists the
   defects in that system which ours deliberately does not copy.

## TL;DR

- **Built:** the full **search → price → book → ticket** path — Authenticate, **Search**, **FareRule**,
  **FareQuote**, **SSR**, **GetAgencyBalance**, **Book**, **Ticket**, **GetBookingDetails** — plus test/live **environment switching** (global + per-user, permission-gated),
  per-user/env result caching, per-user **recent-searches** cache, and full **API request/response
  logging** (global + per-user pages). The **"Select" action is live** and drives a full-page booking
  **wizard** that persists a `quoted` **Booking**.
- **Not built:** **ReleasePNR, Void, Refund** (Phase 5) and the **domestic round-trip two-PNR** split.
  Those endpoint URLs exist but sit dormant in `config/tboair.php`.
- **Two facts that shape the booking work:** the auth **token is valid ~24h** (TBO meeting — the
  published "12h" doc is stale; our 23h TTL is fine), and the **search `TraceId` is valid only ~15
  min**, so pricing/booking must run against a fresh search.
- **Done:** **Phase 1** (FareRule + FareQuote), **Phase 2** (booking domain + passenger UI), and
  **Phase 3** (SSR baggage + meal ancillaries, priced server-side and folded into the booking total).
- **Booking UX:** a **full-page wizard** at `/flights/book` — Select Flight → Guest Details →
  Add-ons → Payment → Confirmation. Steps 1–3 are functional (reuse FareQuote / passengers / SSR);
  Payment shows the wallet balance and warns on a shortfall (no gateway yet); **Confirmation** shows the
  saved `quoted` booking. **Ticketing is a separate, deliberate act on the booking page**, not the end of
  the wizard. **"Select" hands off straight to the wizard**, which does the
  **single** re-price (FareQuote); it shows a price-change gate (old vs new + breakdown; accept/decline)
  **only if the fare changed**, otherwise Guest Details directly. (No duplicate FareQuote on select.)
  Guest Details uses a left section-rail + contained form, and the search bar is **editable in place**
  (the real search form, reused from the flights page via an "embedded" mode); submitting it returns to
  Select Flight with the new search.
- **Recent searches** are real, not sample data: kept per-user in the cache (`RecentSearchStore`, ~1-day
  TTL), appended on each successful search (deduped, capped at 6), and click-to-refill.
- **Phase 4 is built and Book is proven.** On 2026-08-10, booking `MT-FBVMSJVR` created **PNR `984XIX`**
  on the test environment, confirmed by reading it back as `Successful`. It took five refusals to get
  there, and **four of them were TBO's documentation being wrong** — the response envelope, the
  passport flags, the `Title` type, and the GetBookingDetails container. See `01`§5 and `02`.
  **Ticket has still never been called.**
- **Next step:** send **Ticket** — the only untested call left in Phase 4 — then **Phase 6's
  certification matrix**. Two blockers for that matrix: the test environment showed **no LCC
  inventory** (all PR), so cases 1–5 cannot be run as written, and the **domestic round-trip two-PNR**
  split is still unimplemented, affecting four of the 11 cases. Then **Phase 5** (cancel / void /
  refund). Seat maps stay deferred.
- **A live PNR is held on test** — `984XIX`, ticketing deadline `2026-09-07 20:00`. With no ReleasePNR
  yet, releasing it means contacting TBO.
- **Phase 4.0's four prerequisites — all now done** (from TBO's Book/Ticket method pages; detail in
  `01`§5 and `02`):
  1. ~~**Ask TBO whether `ResultId`/`TrackingId` are our `ResultIndex`/`TraceId`.**~~ ✅ **done** —
     answered by the live system rather than TBO: they are the same identifiers, and the mixed hosts
     work in production. See [`04`](04-live-reference-implementation.md).
  2. ~~**Persist the raw FareQuote JSON.**~~ ✅ **done** — nullable `bookings.quote_raw` keeps the
     FareQuote response verbatim, since Book echoes the whole priced itinerary back.
  3. ~~**Extend `Passenger`.**~~ ✅ **done** — address/mobile/email collected once as contact and fanned
     onto every pax row, `isLeadPax` per passenger, Title limited to TBO's three, and
     `TboPassengerMapper` for the string→integer encoding.
  4. ~~**Read TBO's own agency balance.**~~ ✅ **done** — `agencyBalance()` / `hasFundsFor()`,
     `tboair:balance`, and a *Check now* panel in admin Settings. Ticketing draws down **our** TBO
     balance, not the internal e-wallet.

  **Phases 4.0 and 4.1 are both complete.** One small fix still worth doing: our **token refresh
  has no lock**, so concurrent cache misses can each re-authenticate (`04`§7.2). Our `ErrorCode 6`
  re-auth is confirmed working against a real logged expiry — no change needed there (`02`§4).
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
