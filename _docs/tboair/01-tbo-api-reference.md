# TBO Air API — External Reference

> Distilled from the official TBO Air API guide (`https://dealint.tboair.com/APIDocument/APIGuide.aspx`),
> the auto-generated help page (`https://searchapi.tboair.com/Help`), and the **per-method structure
> pages** under `https://dealint.tboair.com/APIDocument/` (`Book.aspx`, `Ticket.aspx`,
> `GetBookingDetails.aspx`, `ReleasePnrRequest.aspx`, `Certification.aspx` — indexed from
> `Default.aspx`, which also links a Sample JSON page per method). This is a working reference for
> building against the API — confirm exact field shapes against those pages and the certification team.

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

> ⚠️ **`Wallet/GetAvailableBalance` is *our* balance with TBO** — the pot ticketing actually draws
> down. It is **not** the agency e-wallet in `_docs/wallet/` (that is what our agencies prepay *us*).
> The two can disagree: a Ticket can fail for insufficient TBO funds while the booking agency's
> internal wallet is fully funded — and by then we have already debited them. See
> `03-implementation-plan.md` Phase 4.

> Note the **controllers**: Void and Refund live under **`Queues`**, not `Booking`. Our dormant
> `refund` config URL currently points at `Booking/RefundApi` — see `02-current-implementation.md`.

Also exposed on the help page but not relevant to us today: `Booking/tripbook` / `tripticket` /
`TripGetBookingDetails` / `tripinvoice`, `Booking/GetAllBookingDetailsByPnrs` (plural),
`Booking/LastMinuteHoldAllowedViaSourceAndAirline`, `Detail/GetDashboardDetails`,
`Detail/GetUapiCredentials`, `Search/AddFunctionTimeDetails`, `Search/GetTBOPricing`,
`GroupBooking/SaveGroupbookingDetailbyXml`, `Authenticate/SendAgentRegistrationEmail`.

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
    and must be passed on Ticket. **No baggage/seat for infants.** The LCC Ticket request carries
    **full passenger detail incl. passport**, where non-LCC may omit it at Ticket (it went in on Book).
  - **Non-LCC / GDS:** call **Book** (creates a held PNR) → then **Ticket**. SSR is typically free.
- **SSR is an LCC feature.** The guide states meal/baggage/seat "can only be availed for LCC flights";
  for non-LCC, **meal and seat are passed as plain strings**, not the SSR option objects.
- **SSR must be the last detail call before ticketing** — re-run GetSSR right before Ticket if the
  selection changed.
- **Meal / baggage / seat arrays must never be `null`** — pass empty arrays instead.
- **Per-passenger fares:** divide `BaseFare` and `Tax` by passenger count — **not only for display**.
  Book/Ticket want the **per-passenger** split, taken from the FareQuote `FareBreakdown` array and
  sent **exactly as received, unmodified**.
- **Domestic round-trip:** the search returns **two result indexes** — outbound (OB) and inbound (IB).
  Run the whole chain **OB first, then IB**, producing **two separate PNRs**.

## 4. Session & identifiers (critical for the booking flow)

| Identifier | Where | Validity | Notes |
| --- | --- | --- | --- |
| **TokenId** | Authenticate → all calls | **~24 hours** | Confirmed 24h by TBO in the integration meeting. The published doc still says "12 hours / one token per day" — **that page is outdated**. Do **not** re-auth per request; cache and reuse. Re-auth on `ErrorCode 6`. |
| **TraceId** | Search → all downstream calls | **~15 minutes** | Ties FareRule/FareQuote/SSR/Book/Ticket to a search. **Expires fast** — a held search result must be booked within the window or re-searched. |
| **ResultIndex** | a specific fare in Search results | within TraceId | Selects the exact itinerary/fare to price and book. |
| **EndUserIp** | every request | — | The whitelisted origin IP. |

> ⚠️ **Token validity is 24h** (per TBO's meeting) — the published guide's "12 hours (00:00–11:59)
> and one token per day" is stale, so our `token_ttl` default of 82800s (23h) sits safely inside it.
> The one hard constraint that remains is the **TraceId ~15-minute window**: we must **not** feed
> FareQuote/Book with search results cached longer than ~15 minutes, or the TraceId will be dead by
> booking time. (If the "one token per day" wording were ever literally enforced, a re-auth would
> invalidate the token a concurrent booking chain is holding. `ErrorCode 6` self-healing covers it,
> but it is a reason not to re-auth speculatively.)

### ⚠️ Open question: `TraceId`/`ResultIndex` vs `ResultId`/`TrackingId`

The **search/detail** documentation we built against names these `TraceId` and `ResultIndex`. The
**booking** method pages name them **`TrackingId`** (Book, Ticket) and **`ResultId`** (Book). The two
are almost certainly the same identifiers under different names — the guide's "Tracking ID is valid
for 15 minutes" matches the TraceId rule exactly — but the endpoints also sit on **different hosts and
route styles**:

| Stage | Host (test) | Route style |
| --- | --- | --- |
| Search / FareRule / FareQuote / SSR | `api-stage.tboair.com` | `InternalAirService.svc/rest/{Method}/` |
| Book / Ticket / GetBookingDetails / ReleasePNR | `xmloutbookingapi.tboair.com` | `api/v1/Booking/{Method}` |

**Confirm with TBO before writing the Book payload:** (a) that `ResultId`/`TrackingId` are our
`ResultIndex`/`TraceId`, and (b) that the `api/v1` booking host accepts a TraceId minted by an
`InternalAirService.svc` search. This is the cheapest question to ask and the most expensive to get
wrong — it was carried as the unresolved "API-generation check" through Phases 0–3.

## 5. Booking method structures (Phase 4/5 surface)

From the per-method pages. Field names are reproduced as documented; treat them as the shape to
**verify against a live call**, not as gospel — TBO's pages lag their API.

### 5.1 Book — non-LCC, creates the PNR

The critical shape fact: **Book is echo-heavy**. It is *not* `(TraceId, ResultIndex, passengers)` —
it re-sends the whole priced itinerary back to TBO.

**Top level:** `ResultId`, `TokenId`, `IPAddress`, `IsHoldEligibleForLcc` (bool), `NoOfSeatAvailable`
(int, *from the search response*), `OperatingCarrier` (*airline name from the search response*),
`SegmentIndicator` (optional; `1`=outbound, `2`=inbound).

**Flight detail (per segment):** `Airline`, `FlightNumber`, `DepartureTime`, `ArrivalTime`,
`BookingClass` (*from FareQuote*), `FlightStatus`, `ETicketEligible`, `StopOver`, `Stops`,
`IncludedBaggage`, `CabinBaggage`, and optional `Duration`, `CabinClass`.

**Airport/city (per endpoint):** `AirportCode`, `CityCode`, `CityName`, `CountryCode`, `CountryName`
mandatory; `AirportName`, `Terminal` optional.

**Passenger array:**

| Field | Notes |
| --- | --- |
| `Title` | **enum int** — `Mr=0, Miss=1, Mrs=2` (no Ms / Mstr) |
| `FirstName`, `LastName` | |
| `Type` | **enum int** — `Adult=1, Child=2, Infant=3, Senior=4, Youth=5` |
| `DateOfBirth` | |
| `Gender` | **enum int** — `Male=1, Female=2` |
| `PassportNo`, `PassportExpiry` | |
| `AddressLine1`, `AddressLine2` | mandatory |
| `Nationality` | as `CountryCode` + `CountryName` |
| `City` | as `CityCode` + `CityName` |
| `Mobile1`, `Mobile1CountryCode`, `Email` | mandatory |
| `IsLeadPax` | bool |
| `FFAirline`, `FFNumber` | mandatory keys — **send NULL** |
| `AirlineRemarks` | optional |

**Per-passenger fare** (all taken from the FareQuote `FareBreakdown`, **sent exactly as received**):
`TotalFare`, `BaseFare`, `Tax`, `OtherCharges`, `ServiceFee`, `Currency`, optional `AgentMarkup`;
plus `Origin`, `Destination`, `Airline`, `FareRestriction`, `FareBasisCode`, `DepartureDate`,
`ValidatingAirlineCode`, `JourneyType` (`OneWay=1, Return=2, MultiWay=3`), `LastTicketDate` (nullable),
`TravelDate`, `SearchType` (`OneWay=1, Return=2, MultiWay=3, AdvanceSearch=4`), `NonRefundable`,
`IsDomestic`, `IsLcc`, `PointOfSale` (departure **country code**), `RequestOrigin` (departure
**country name**), `EndUserBrowserAgent`, `UserData` (passenger name).

**Ancillaries:** `Meal` as `Code` + `Description`; `Seat` may be sent as NULL. (Arrays themselves must
not be null — see §3.)

### 5.2 Ticket — LCC books+issues, non-LCC issues a held PNR

- **Non-LCC request** is small: `TokenId`, `TrackingId`, `IPAddress`, `EndUserBrowserAgent`,
  `UserData`, `PointOfSale`, `RequestOrigin`, `IsHoldEligibleForLcc`, `NoOfSeatAvailable`,
  `OperatingCarrier`, conditional `SegmentIndicator`.
- **LCC request** additionally carries the **full flight block and full passenger detail including
  passport** — essentially the Book payload, because Ticket *is* the booking call for LCC.
- **Response:** the fare echo (`TotalFare`, `BaseFare`, `OtherCharges`, `ServiceFee`, `Origin`,
  `Destination`, `Airline`, `DepartureDate`, `ValidatingAirlineCode`), plus **`PNR`**,
  **`ResponseStatus`**, `IsLcc`, and the two change flags **`IsPriceChanged`** / **`IsTimeChanged`**.

### 5.3 Booking status enum — 10 values, three of them ambiguous

```
NotSet=0  Successful=1  Failed=2      OtherFare=3    OtherClass=4
BookedOther=5  NotConfirmed=6  Pending=7  InProgress=8  Cancelled=9
```

`Pending`, `InProgress` and `NotConfirmed` are precisely the states where the write response must
**not** be trusted — reconcile via `GetBookingDetails` before deciding anything (and never before
retrying). **Certification Case 11 explicitly tests `InProgress` handling**, so this path is required,
not defensive polish.

### 5.4 GetBookingDetails — the source of truth

- **Request:** `PNR` + `TokenId`. Note it is keyed on **PNR**, not BookingId, in this generation — so
  the PNR is the reconciliation key we must persist first.
- **Response:** itinerary level (`PNR`, `IsDomestic`, `Source`, `Origin`/`Destination`, `AirlineCode`,
  `ValidatingAirlineCode`, `IsLCC`, `NonRefundable`, `FareType`, `TicketStatus`); **passengers**
  (`PaxID`, name, `PaxType`, `DateOfBirth`, `Gender`, passport, `IsLeadPax`, `FFAirlineCode`/`FFNumber`);
  **tickets** (**`TicketId`**, `TicketNumber`, `IssueDate`, `ValidatingAirline`, `Status`, `Remarks`);
  segments; fare rules; `InvoiceNo` / `InvoiceCreatedOn`; and an optional `Penalty` block.

### 5.5 ReleasePNR — cancel an unticketed hold

- **Request:** `PNR`, **`LastName`**, `Remarks` (e.g. `"RELEASE PNR"`), `TokenId`, `IPAddress`.
- **Response:** `ResponseMessage`, `IsSuccess`, `Errors[]`, `Alerts[]`, `TokenId`, `TrackingId`.
- Applies to "hold bookings which you do not want to ticket". Supports **full or partial** cancellation
  (per passenger or per sector), and **all `TicketId`s must be sent for a full cancellation** — which
  means the TicketIds from GetBookingDetails have to be stored, not just read.

## 6. Error handling

- Responses carry an `Error` object with **`ErrorCode`** + `ErrorMessage` (and often `Errors[].UserMessage`).
- **`ErrorCode == 0`** ⇒ success.
- **`ErrorCode == 6`** ⇒ invalid/expired session token ⇒ **re-authenticate once and retry** (our
  self-healing backstop already does this for Search).
- Always persist the raw request/response for support (TBO requires attached logs for tickets).

## 7. Certification (go-live gate)

The exact **11 cases**, from `Certification.aspx` — 5 LCC, 4 non-LCC, 2 behavioural:

| # | Carrier | Passengers | Journey |
| --- | --- | --- | --- |
| 1 | LCC | 2 adults | one-way, non-stop |
| 2 | LCC | 2 adults + 1 child + 1 infant | one-way, non-stop |
| 3 | LCC | 1 adult + 1 child + 1 infant | return, 1 stop |
| 4 | LCC | 1 adult + 1 child + 1 infant | one-way, 1 stop — **with baggage + meal** |
| 5 | LCC | 2 adults + 1 child + 1 infant | return, 1 stop — **with baggage + meal** |
| 6 | Non-LCC | 2 adults | one-way, non-stop |
| 7 | Non-LCC | 2 adults + 1 child + 1 infant | one-way, 1 stop — **with meal** |
| 8 | Non-LCC | 3 adults | return, non-stop |
| 9 | Non-LCC | 2 adults + 1 child + 1 infant | return, 1 stop — **with meal** |
| 10 | Non-LCC | — | **price / schedule change** verification |
| 11 | Both | — | **`InProgress` status handling** |

**Submission:** the JSON request/response **plus the PNR numbers**, submitted **case by case, not
consolidated**. `GetBookingDetails` must appear in every case. Verification takes **4–5 working days**;
after sign-off TBO issues live credentials, and the **static public IP is whitelisted last**. If a PNR
is generated but the Ticket call errors supplier-side, send the logs — support assesses those during
certification rather than failing the case outright.

Our per-user + global **API Logs** already capture the request/response evidence, so submission is
mostly a matter of running the matrix and exporting.

## Sources

- [TBO API documentation index](https://dealint.tboair.com/APIDocument/Default.aspx) — links a
  *Method Structure* + *Sample JSON* page per method, plus Compression, Pathway, Flight FAQ and
  Flight Validation pages we have not yet mined.
- [TBO Air API Guide](https://dealint.tboair.com/APIDocument/APIGuide.aspx)
- [TBO Air API Help (endpoint list)](https://searchapi.tboair.com/Help)
- Method structures: [Book](https://dealint.tboair.com/APIDocument/Book.aspx) ·
  [Ticket](https://dealint.tboair.com/APIDocument/Ticket.aspx) ·
  [GetBookingDetails](https://dealint.tboair.com/APIDocument/GetBookingDetails.aspx) ·
  [ReleasePNR](https://dealint.tboair.com/APIDocument/ReleasePnrRequest.aspx) ·
  [Certification](https://dealint.tboair.com/APIDocument/Certification.aspx)
- [TBO Flights API overview (phptravels)](https://phptravels.com/tbo-flights-api-integration)

> The SRDV "sample JSON" pages previously cited here are marketing pages, not documentation — they
> carry no field data. Use TBO's own `*_Json.aspx` sample pages instead.
