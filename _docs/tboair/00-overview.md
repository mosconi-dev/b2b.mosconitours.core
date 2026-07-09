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

## Related in-app tooling

- Admin → **Settings**: switch global env, per-env token TTL + seed/flush.
- Admin → **Users → Logs**: per-user API calls + activity.
- Console: `tboair:auth`, `tboair:search`, `tboair:logs` (search/auth smoke tests require a
  TBO-whitelisted server).
- The `/admin` area and every `can:`-gated route/action above are governed by **RBAC** — see
  [`../rbac/00-overview.md`](../rbac/00-overview.md).
