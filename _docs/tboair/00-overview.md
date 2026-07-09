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

- **Built today:** Authenticate + **Search** only, with test/live **environment switching**
  (global + per-user, permission-gated), per-user/env result caching, and full **API request/response
  logging** (global + per-user pages). The search UI works; the **"Select"/book action is inert**.
- **Not built:** FareRule, FareQuote, SSR, Book, Ticket, GetBookingDetails, ReleasePNR, Refund — and
  there's no booking/PNR persistence yet. The endpoint URLs already exist (dormant) in
  `config/tboair.php`.
- **Two facts that shape the booking work:** the auth **token is valid ~24h** (TBO meeting — the
  published "12h" doc is stale; our 23h TTL is fine), and the **search `TraceId` is valid only ~15
  min**, so pricing/booking must run against a fresh search.
- **Done:** **Phase 1 (FareRule + FareQuote)** — select an offer → re-price + rules in a confirm modal.
- **Next step:** Phase 2 (booking domain + passengers). See `03-implementation-plan.md`.

## Related in-app tooling

- Admin → **Settings**: switch global env, per-env token TTL + seed/flush.
- Admin → **Users → Logs**: per-user API calls + activity.
- Console: `tboair:auth`, `tboair:search`, `tboair:logs` (search/auth smoke tests require a
  TBO-whitelisted server).
