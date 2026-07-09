# TBO Air API — External Reference

> Distilled from the official TBO Air API guide (`https://dealint.tboair.com/APIDocument/APIGuide.aspx`)
> and the auto-generated help page (`https://searchapi.tboair.com/Help`). This is a working
> reference for building against the API — always confirm exact field shapes against the live
> "Detailed Request and Response" pages and the certification team.

## 1. What TBO Air is

TBO Air (Travel Boutique Online) is a flight consolidator API. It aggregates **GDS/full-service
carriers (non-LCC)** and **low-cost carriers (LCC, 700+)** behind one JSON API. There is **no
payment gateway** on TBO's side — the agency implements payment/collection itself and TBO deducts
from the agency wallet/credit on ticketing.

- **Transport:** JSON over HTTPS, `POST` for all business operations.
- **Access control:** requests must originate from an **IP whitelisted** with TBO, and every request
  carries `EndUserIp` + a `TokenId` from Authenticate.
- **Environments:** a **staging/test** stack and a **production/live** stack with separate hosts and
  separate credentials (see `02-current-implementation.md` for our host mapping).

## 2. Endpoints (from the help page)

Routes are versioned: `api/{apiVersion}/{Controller}/{Action}` (e.g. `api/V10/Search/Search`).

| Area | Method + route | Purpose |
| --- | --- | --- |
| **Auth** | `POST api/{v}/Authenticate/ValidateAgency` | Exchange credentials → `TokenId` (+ agency/member info); token good ~24h |
| **Balance** | `POST api/{v}/Wallet/GetAvailableBalance` | Agency wallet balance (a.k.a. GetAgencyBalance) |
| **Search** | `POST api/{v}/Search/Search` | Synchronous flight search → results + `TraceId` |
| | `POST api/{v}/Search/SearchAsync` | Async search variant |
| **Fare** | `POST api/{v}/Detail/FareRule` | Fare rules / cancellation policy for a result |
| | `POST api/{v}/Detail/FareQuote` | Binding re-price of a result (price may change; flags `IsLCC`, passport-required) |
| **Ancillaries** | `POST api/{v}/Detail/GetSSR` | Special service requests: baggage, meal, seat (LCC) |
| | `POST api/{v}/Detail/GetFreeMeals` / `AddOnFares` | Free meals / add-on fares |
| **Booking** | `POST api/{v}/Booking/Book` | Create a **PNR** (non-LCC / GDS hold) |
| | `POST api/{v}/Booking/Ticket` | Issue a ticket (LCC: book+ticket in one call; non-LCC: after Book) |
| | `POST api/{v}/Booking/GetBookingDetails` | **Mandatory** state read after every booking step |
| | `POST api/{v}/Booking/GetAllBookingDetailsByPnr` | Look up a booking by PNR |
| | `POST api/{v}/Booking/GetLastTicketDate` | Ticketing time-limit for a held PNR |
| | `POST api/{v}/Booking/ReleasePNR` | Cancel/release an unticketed PNR |
| | `POST api/{v}/Booking/ReIssueDetail` | Re-issue details |
| **Queues** | `POST api/{v}/Queues/GetVoidAmountDetails` / `VoidRequest` | Void a ticket (same-day) |
| | `POST api/{v}/Queues/RefundRequest` / `RefundApi` | Online refund |
| | `POST api/{v}/Queues/GetSupplierInfo` | Supplier info |

## 3. End-to-end booking workflow

The **required call order** (per the API guide):

```
Authenticate
   └─> Search ──> (pick a result: ResultIndex)
          └─> FareRule            (rules / cancellation policy)
          └─> FareQuote           (re-price + confirm; reveals IsLCC, passport req.)
                 └─> GetSSR        (only if selling baggage/meal/seat — LCC)
                        └─> Book   (NON-LCC only → creates PNR)
                        └─> Ticket (LCC → book+ticket; NON-LCC → ticket the PNR)
                               └─> GetBookingDetails   (MANDATORY after every step)
                                      └─> ReleasePNR / Void / Refund (as needed)
```

Rules that shape the design:

- **Call `GetBookingDetails` after every booking-affecting step** to read authoritative status.
- **LCC vs non-LCC** (from `FareQuote.IsLCC`):
  - **LCC:** call **Ticket** directly (it books + issues in one). Baggage/meal/seat via **GetSSR**
    and must be passed on Ticket. **No baggage/seat for infants.**
  - **Non-LCC / GDS:** call **Book** (creates a held PNR) → then **Ticket**. SSR is typically free.
- **SSR must be the last detail call before ticketing** — re-run GetSSR right before Ticket if the
  selection changed.
- **Meal / baggage / seat arrays must never be `null`** — pass empty arrays instead.
- **Per-passenger fares:** divide `BaseFare` and `Tax` by passenger count when displaying per-pax.
- **Domestic round-trip:** the search returns **two result indexes** — outbound (OB) and inbound (IB).
  Run the whole chain **OB first, then IB**, producing **two separate PNRs**.

## 4. Session & identifiers (critical for the booking flow)

| Identifier | Where | Validity | Notes |
| --- | --- | --- | --- |
| **TokenId** | Authenticate → all calls | **~24 hours** | Confirmed 24h by TBO in the integration meeting. The published doc still says "12 hours / one token per day" — **that page is outdated**. Do **not** re-auth per request; cache and reuse. Re-auth on `ErrorCode 6`. |
| **TraceId** | Search → all downstream calls | **~15 minutes** | Ties FareRule/FareQuote/SSR/Book/Ticket to a search. **Expires fast** — a held search result must be booked within the window or re-searched. |
| **ResultIndex** | a specific fare in Search results | within TraceId | Selects the exact itinerary/fare to price and book. |
| **EndUserIp** | every request | — | The whitelisted origin IP. |

> ⚠️ **Token validity is 24h** (per TBO's meeting) — the published guide's "12 hours" is stale, so
> our `token_ttl` default of 82800s (23h) sits safely inside it. The one hard constraint that remains
> is the **TraceId ~15-minute window**: we must **not** feed FareQuote/Book with search results cached
> longer than ~15 minutes, or the TraceId will be dead by booking time.

## 5. Error handling

- Responses carry an `Error` object with **`ErrorCode`** + `ErrorMessage` (and often `Errors[].UserMessage`).
- **`ErrorCode == 0`** ⇒ success.
- **`ErrorCode == 6`** ⇒ invalid/expired session token ⇒ **re-authenticate once and retry** (our
  self-healing backstop already does this for Search).
- Always persist the raw request/response for support (TBO requires attached logs for tickets).

## 6. Certification (go-live gate)

Before production access TBO runs a certification (~3–4 working days) requiring **~11 test cases**
covering: 1 adult; multiple adults; adult+child; adult+infant; adult+child+infant; one-way; return;
domestic vs international; non-stop vs 1-stop; LCC and non-LCC; and a full book→ticket→cancel cycle.
Each must include the JSON request/response logs. Our per-user + global **API Logs** already capture
these, which will make certification submission straightforward.

## Sources

- [TBO Air API Guide](https://dealint.tboair.com/APIDocument/APIGuide.aspx)
- [TBO Air API Help (endpoint list)](https://searchapi.tboair.com/Help)
- [TBO Flights API overview (phptravels)](https://phptravels.com/tbo-flights-api-integration)
- [FareQuote sample JSON (SRDV, TBO-compatible)](https://www.srdvtechnologies.com/doc/flight/v8/farequote-sample-json)
