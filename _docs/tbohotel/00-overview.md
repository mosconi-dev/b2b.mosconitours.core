# TBO Hotel Integration — Docs

Documentation for the **TBO Holidays** hotel-supplier integration in `b2b.mosconitours.core`.
The full lifecycle is built: search, PreBook, Book, cancel, voucher and reconciliation.

## Read in this order

1. **[01 — TBO API Reference](01-tbo-api-reference.md)** — the external TBO Hotel API, condensed from
   the v2.1 specification: Basic Auth, the eleven endpoints, timeouts, the `Status.Code` table, every
   method's request/response, the enumerations — and the eight places the spec contradicts itself.
2. **[02 — The Live Reference Implementation](02-live-reference-implementation.md)** — how
   `b2b.philippineexplorer.com`, the system live today, actually books hotels. What it proves about
   the API, and the six defects ours deliberately does not copy.
3. **[03 — Implementation Plan](03-implementation-plan.md)** — nine architectural decisions and eight
   phases, from shared foundations to go-live.

For the sibling integration, whose architecture this one reuses, start at
[`../tboair/00-overview.md`](../tboair/00-overview.md).

## TL;DR

- **Status: the lifecycle is complete.** The shared seams are supplier-agnostic
  (`supplier_api_logs`, `SupplierEnvironmentResolver`, a `product`-bearing booking spine,
  `Confirmed`/`Cancelling` statuses), the hotel client talks to TBO for real, and the catalogue is
  loaded: **249 countries, 194 Philippine cities, 3,364 hotels** across Manila and Cebu City,
  curated per city at `/admin/hotel-catalogue`. **Search works end to end**: Cebu City returns 118
  available properties in about five seconds. **Next: catalogue breadth** — five destinations of 194 are carried, and no city list outside the Philippines has been pulled.
- **The base-URL question is settled:** the spec's `https://api.tbotechnology.in/HotelAPI`, not
  production's `http://api.tbotechnology.in/TBOHolidays_HotelAPI`. And the hotel API is **not
  IP-restricted**, so unlike TBO Air the read side is fully developable from a dev machine.
- **This is a different API from TBO Air, not another controller on it.** HTTP **Basic Auth** on
  every call — no token, no session, no re-auth. Success is `Status.Code == 200` in the body.
  **`PreBook`** replaces FareQuote, **`Book`** vouchers in one step (there is no hold, no Ticket), and
  titles are **`Mr`/`Mrs`/`Ms`** — a different set from the flight side.
- **The clock is 30 minutes**, search to book, and what expires is the **`BookingCode`**
  (`Status.Code 315`). Result caches stay well inside it.
- **`PreBook` is binding.** The spec is explicit: its cancellation policy and norms are final. The
  live system stores the *search* price and policy instead — the first defect not to copy.
- **There is no "search a city" call.** `Search` demands `HotelCodes`, ~100 per request, so a local
  catalogue is a precondition, not an optimisation. The live system loads `limit(100)` unordered and
  shows an arbitrary slice of any city bigger than that; ours searches the whole city through bounded
  concurrent chunks and says plainly when some could not be reached.
- **The catalogue sync in production is half-built:** countries and cities only, insert-only, and
  **nothing ever writes `tbo_hotels`** — the table every search depends on was loaded by hand and has
  no refresh path.
- **A timed-out Book is the case that matters.** §10 makes it *mandatory* to call `BookingDetail` by
  `BookingReferenceId` 120 seconds later. Production never does, and its `BookingReferenceId` is a
  per-search session id rather than a durable booking reference. Ours sends `bookings.reference` and
  reconciles from a queued job.
- **Two balances again:** the agency e-wallet (ours, debited under lock at booking creation) and
  TBO's credit limit (theirs, with **no read endpoint in this spec** — insufficient funds arrive only
  as `Status.Code 300` on Book).
- **Architecture:** a parallel `App\Services\TboHotel` namespace — no premature supplier
  abstraction — on top of two generalised seams (`supplier_api_logs`, `SupplierEnvironmentResolver`)
  and **one shared booking spine** with a `hotel_bookings` detail table. `BookingStatus` gains
  `Confirmed` and `Cancelling`; `bookings.result_index` must become nullable before any non-flight
  row can exist.
- **Deliberately out of scope:** card payments (we book on TBO's credit limit), multi-currency, and
  **markup** — which is a cross-product concern to be designed once, not grown privately inside a
  second supplier.
- **Tooling:** `php artisan tbohotel:ping [--country=PH]` checks connectivity and credentials, and
  prints the URL it called — it exercises both a GET (CountryList) and a POST (CityList), since a
  base URL that answers one and not the other is a real possibility.
  `php artisan tbohotel:sync {countries|cities|hotels|details}` refreshes the catalogue, and
  **Admin → TBO Hotel** does the same from the browser while showing what the last runs skipped.
  Hotel calls appear at `/api-logs?supplier=tbohotel`.
