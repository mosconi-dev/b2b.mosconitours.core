# TBO Hotel — API Reference

Condensed from **TBOH Hotel API Specification v2.1** (`TBOH Hotel API Specifications(V2.1) 1.pdf`,
71 pages, revised 24 Apr 2026). Section numbers below are the PDF's.

This is a **different API from TBO Air**, not another controller on the same one. The differences
that shape the build are listed first, because assuming Air's model here will produce wrong code.

---

## 1. How it differs from TBO Air — read this first

| | TBO Air | TBO Hotel |
| --- | --- | --- |
| **Auth** | `Authenticate` → `TokenId` on every request, ~24h TTL, `ErrorCode 6` re-auth | **HTTP Basic Auth on every call.** No token, no session, no expiry, no re-auth wrapper |
| **Session key** | `TraceId` + `ResultIndex`, ~15 min | **`BookingCode`** — one opaque token per bookable unit, valid inside a **30-minute** search→book window |
| **Re-price step** | `FareQuote` | **`PreBook`** — and it is *binding*: §18 says PreBook's **cancellation policy and norms are final** |
| **Money step** | `Book` (hold) then `Ticket` (spend) | **`Book`** alone — one call, vouchers immediately. No hold, no two-step |
| **Success signal** | `ResponseStatus` / `Error.ErrorCode` | **`Status.Code`** — an HTTP-like integer in the body (§5) |
| **Ambiguity rule** | "call GetBookingDetails after each state change" (advice) | **Mandatory**: on timeout/failure at Book, call `BookingDetail` by `BookingReferenceId` **after 120 s** (§10) |
| **Titles** | `Mr` / `Mrs` / `Miss` (a real Book refused `Ms`) | **`Mr` / `Mrs` / `Ms`** (§8.1) — a different set. Do not share the flight enum |
| **Pax types** | Adult / Child / Infant, integer-encoded | **`Adult` / `Child`** only, sent as **words**. No infant type |
| **Balance** | `Wallet/GetAvailableBalance` endpoint | **No balance endpoint in this spec.** Insufficient funds arrives only as `Status.Code 300` on Book |

---

## 2. Authentication, endpoints, timeouts (§2–4)

**Basic Auth** with the TBO-issued username/password, `Content-Type: application/json`, POST for
everything except two GETs.

```
Test BaseURL:  https://api.tbotechnology.in/HotelAPI      (§3, spec v2.1)
Live BaseURL:  {Live-URL}/HotelAPI                        (redacted in the PDF)
Convention:    {BaseURL}/{MethodName}
```

> ✅ **Settled 2026-08-12 — the spec's URL is the right one.** Production calls
> `http://api.tbotechnology.in/**TBOHolidays_HotelAPI**/Search`, a different path *and* plain HTTP,
> but the spec's change log records *"Change in staging Endpoint URL"* on **21 Apr 2026** and the
> spec wins: `php artisan tbohotel:ping --country=PH` against
> `https://api.tbotechnology.in/HotelAPI` returned **249 countries** (GET, 1197 ms) and **194
> Philippine cities** (POST, 1129 ms) on the `MoscaniToursTest` credentials.
>
> Two things came free with that call. The credentials are good, and — unlike TBO Air, where a
> dev machine cannot authenticate at all — **the hotel API is not IP-restricted**, so the whole
> read side can be developed and tested locally.

| Method | Path | HTTP | Recommended timeout (§4) |
| --- | --- | --- | --- |
| Search | `/Search` | POST | **5–23 s** |
| PreBook | `/PreBook` | POST | **23 s** |
| Book | `/Book` | POST | **120 s** |
| BookingDetail | `/BookingDetail` | POST | — |
| Cancel | `/Cancel` | POST | — |
| CountryList | `/CountryList` | **GET** | — |
| CityList | `/CityList` | POST | — |
| HotelCodeList | `/hotelcodelist` | **GET** | — |
| TBOHotelCodeList | `/TBOHotelCodeList` | POST | — |
| HotelDetails | `/HotelDetails` | POST | — |
| BookingDetailsBasedOnDate | `/BookingDetailsbasedondate` | POST | — |

**The whole search→book flow must complete inside 30 minutes** (§4 note). After that the
`BookingCode` is dead and Book answers `315`.

## 3. Response status codes (§5)

Every response carries `Status: { Code, Description }`. The code is the *only* reliable success
signal — HTTP 200 is returned with failures inside.

| Code | Name | Meaning for us |
| --- | --- | --- |
| **200** | SUCCESS | Rooms available / rate still valid / booking vouchered / booking cancelled |
| **201** | NO_AVAILABILITY | No rooms for the criteria. **Not an error** — an empty result |
| **207** | RATE_UNAVAILABLE | The rate is gone. Re-search; do not retry |
| **300** | INSUFFICIENT_BALANCE | **Our** TBO credit limit is exhausted. Distinct from the agency e-wallet |
| **315** | BOOKINGCODE_EXPIRED | The 30-minute window elapsed. Re-search |
| **400** | INVALID_REQUEST | A parameter is wrong — our bug, log the payload |
| **401** | UNAUTHORIZED | Bad credentials |
| **402** | AGENT_BLOCKED | Agency blocked at TBO |
| **405** | BOOKING_FAIL | Booking not created |
| **429** | LIMIT_EXCEEDED | **QPS exceeded** — matters the moment we fan out chunked searches |
| **479** | CANCEL_FAIL | Cancellation refused |
| **500** | UNEXPECTED_ERROR | Send full JSON request+response to `apisupport@tbo.com` |

## 4. Search (§6)

```jsonc
{
  "CheckIn": "2026-09-16", "CheckOut": "2026-09-17",   // YYYY-MM-DD
  "HotelCodes": "1247101,1120548,…",                    // comma-separated, ~100 max recommended
  "GuestNationality": "PH",                             // ISO 3166-1 alpha-2, LEAD GUEST's
  "PaxRooms": [ { "Adults": 2, "Children": 1, "ChildrenAges": [8] } ],
  "ResponseTime": 23.0,
  "IsDetailedResponse": false,
  "Filters": { "Refundable": false, "NoOfRooms": 1, "MealType": "All" }
}
```

- **`HotelCodes` is required.** There is no "search a city" call — a city search means holding a
  local catalogue and sending its codes, ~100 per request. This is the whole reason Phase 2 exists.
- Occupancy is **per room**: `Adults` 1–8, `Children` 0–4, `ChildrenAges` 0–18 with `length ==
  Children`.
- `Filters.MealType` vocabulary is **`All` / `WithMeal` / `RoomOnly`** — a *different* set from the
  `MealType` returned per room. Do not reuse one enum for both.
- **`IsDetailedResponse`**: §18 recommends **`false`** ("decrease the overall response size and
  time"). `true` adds `DayRates` and detailed `CancelPolicies`.

**Response** — `HotelResult[]`, each `{ HotelCode, Currency, Rooms[] }`. Per room:

| Field | Notes |
| --- | --- |
| `Name` | **Array of string** — one entry per room in a multi-room booking |
| `BookingCode` | e.g. `1120548!TB!2!TB!4ee85bb9-…` — opaque, long, `!`-delimited. **Store as `text`** |
| `Inclusion` | free text, e.g. "Free WiFi" |
| `TotalFare`, `TotalTax` | decimals, the bookable unit's total (all rooms) |
| `ExtraGuestCharges`, `RecommendedSellingRate` | arrive as **strings** in the samples despite being typed decimal — parse defensively |
| `DayRates` | list of arrays of `{ BasePrice }`, detailed responses only |
| `RoomPromotion` | list of strings; first element = first room |
| `MealType` | per-room meal — see §17 enum below |
| `IsRefundable`, `WithTransfers` | booleans |
| `CancelPolicies[]` | `{ Index?, FromDate, ChargeType, CancellationCharge }`. **`Index` is the room index; when absent the policy applies to the whole booking** |
| `Supplements` | **a list of lists** in the sample (`[[{…}]]`) — `{ Index, Type, Description, Price, Currency }` |

**`Supplements[].Type`** is `Included` (already in the total) or **`AtProperty`** (the guest pays
the hotel directly). §18: *"Kindly display the mandatory supplements i.e, AtProperty before/at the
booking step"*.

No availability answers `{ "Status": { "Code": 201, … } }` with **no `HotelResult` key at all** —
not an empty array. Parse for absence.

## 5. PreBook (§7)

```json
{ "BookingCode": "1247101!TB!1!TB!d6f7ec94-…", "PaymentMode": "Limit" }
```

Response mirrors Search's room object and **adds** `Amenities` (list of string), `RateConditions`
(list of string — the hotel's norms) and `CreditCardBillingOptions` (card payment only).

**This response is the contract.** §18: *"Cancellation Policy and Norms received in the PreBook
response will be considered as final for the booking itinerary."* Therefore:

1. The price we charge and store is **PreBook's `TotalFare`**, not Search's.
2. The cancellation policy we display, store and later compute a refund from is **PreBook's**.
3. `RateConditions` belongs on the voucher.

A price move between Search and PreBook is normal and expected — it is a **gate to show the
agent**, not an error (the live system treats it as one; see [`02`](02-live-reference-implementation.md) §4).

## 6. Book (§8) — the money step

```jsonc
{
  "BookingCode": "1157709!TB!1!TB!9c5b1815-…",
  "CustomerDetails": [                       // one entry per ROOM
    { "CustomerNames": [
        { "Title": "Mr", "FirstName": "Juan", "LastName": "Dela Cruz", "Type": "Adult" },
        { "Title": "Ms", "FirstName": "Ana",  "LastName": "Dela Cruz", "Type": "Child" }
    ] }
  ],
  "ClientReferenceId": "MT-…",               // our reference
  "BookingReferenceId": "MT-…",              // our reference — THE reconciliation key
  "TotalFare": 152.88,
  "EmailId": "agent@example.com",
  "PhoneNumber": "+639171234567",
  "BookingType": "Voucher",
  "PaymentMode": "Limit"
}
```

- `Title` ∈ **`Mr` / `Mrs` / `Ms`**. `Type` ∈ **`Adult` / `Child`**, sent as words.
- `PaymentMode: "Limit"` books against TBO's credit limit. `SavedCard` / `NewCard` add a
  `PaymentInfo` object (card number, CVV, expiry, holder name, `BillingAmount`,
  `BillingCurrency`, `CardHolderAddress`). **Cards are out of scope** — we hold a TBO limit.
- **`BookingReferenceId` is the single most important field in this API for us.** It is the key
  `BookingDetail` accepts when no `ConfirmationNumber` came back, which is exactly the timeout case.
  It must be generated and persisted *before* the call.

**Response** is thin: `{ Status, ClientReferenceId, ConfirmationNumber }`. `ConfirmationNumber`
(e.g. `FL1IMA`) is TBO's booking reference — the hotel analogue of a PNR.

## 7. Cancel (§9)

`{ "ConfirmationNumber": "FL1IMA" }` → `{ Status: { Code: 200, Description: "Cancelled" },
ConfirmationNumber }`. `479` is a refusal.

**The response does not state the cancellation charge.** Whatever the guest is charged comes from
the PreBook policy we stored, and is only settled definitively on TBO's invoice.

## 8. BookingDetail (§10) — the authoritative read

Request by **either** `ConfirmationNumber` **or** `BookingReferenceId`, plus `PaymentMode`.

> **§10, verbatim:** *"In case of timeout/failure/http/network related error in book response then
> it is mandatory to call the BookingDetail method by using BookingReferenceId after 120 seconds of
> book response."*

Response `BookingDetail` object: `BookingStatus`, `VoucherStatus`, `ConfirmationNumber`,
**`HotelConfirmationNumber`**, `InvoiceNumber`, `CheckIn`/`CheckOut`/`BookingDate`, `NoOfRooms`,
`HotelDetails{ HotelName, Rating, AddressLine1/2, Map, City }`, `Rooms[]` (now with a per-room
**`Status`** — *"Not Cancelled" / "Cancelled"*, added 24 Apr 2026), `CustomerDetails[]`,
`RateConditions[]`.

### 8.1 The HCN retrieval SLA (§10)

`HotelConfirmationNumber` (HCN) is the hotel's own reference and **arrives late**. TBO asks clients
to poll for it rather than wait on email, and **only issues it when check-in is within 30 days**.

| Priority | Check-in window | First call, after booking |
| --- | --- | --- |
| P0 | < 24 h | 3 h |
| P1 | 24–48 h | 4 h |
| P2 | 2–3 days | 6 h |
| P3 | 3–5 days | 12 h |
| P4 | 5–8 days | 48 h |
| P4+ | 8–14 days | 72 h |
| P5 | 14–30 days | 120 h |

Then **retry hourly, maximum 3 retries**; still nothing → raise an ops ticket. This is a scheduled
job, and neither our system nor the live one has anything like it today.

## 9. Static content methods (§11–14, 16)

| Method | HTTP | Request | Returns |
| --- | --- | --- | --- |
| `CountryList` | GET | — | `CountryList[] { Code (ISO-2), Name }` |
| `CityList` | POST | `{ CountryCode }` | `CityList[] { Code (numeric string), Name }` |
| `hotelcodelist` | GET | — | `HotelCodes[]` — **every** TBO hotel code, undifferentiated |
| `TBOHotelCodeList` | POST | `{ CityCode, IsDetailedResponse }` | Hotels **for one city**, with name/address/description/images |
| `HotelDetails` | POST | `{ Hotelcodes, Language, IsRoomDetailRequired? }` | Full hotel record; with the flag, per-room detail |

`HotelDetails` returns `HotelCode, HotelName, Description, HotelFacilities, Attractions, Images,
Address, PinCode, CityId, CityName, CountryCode, CountryName, PhoneNumber, FaxNumber, HotelRating,
Map (`lat|lng`), CheckInTime, CheckOutTime`.

**Room mapping (added 27 Oct 2025).** `IsRoomDetailRequired: true` returns `{ RoomName, RoomId,
RoomSize, RoomDescription, imageURL[] }`. Search's `RoomID` matches `HotelDetails.RoomId`, which is
how room photos get onto search results. **`RoomID = 0` means no mapping is available** — a normal
case to handle, not an error.

`TBOHotelCodeList` (by city) is the practical catalogue loader; `hotelcodelist` (everything) is
not, since it carries no city association.

### 9.1 What these two actually return (measured 2026-08-12)

The §16 parameter table is wrong about `TBOHotelCodeList`, and the difference decides how the
catalogue is built. Observed against the test environment:

| | `TBOHotelCodeList` (per city) | `HotelDetails` (per hotel) |
| --- | --- | --- |
| Fields | `HotelCode, HotelName, Latitude, Longitude, HotelRating, Address, CountryName, CountryCode, CityName` | adds `Description, HotelFacilities[], Attractions[], Images[], PinCode, PhoneNumber, Email, HotelWebsiteUrl, FaxNumber, Map, CheckInTime, CheckOutTime, HotelFees[], CityId` |
| `IsDetailedResponse` | **no effect** — byte-identical either way, despite §16 promising description and images | n/a |
| Cost | Manila (1495 hotels): **406 KB in 2.0 s** | batches: 25 → 304 KB/1.8 s, 50 → 538 KB/2.1 s, **100 → 970 KB/2.6 s** |

So the catalogue is loaded in two passes, not one: the code list is cheap and complete and carries
everything a search result needs, while descriptions and images cost one batched call per ~50 hotels
and belong in a separate, resumable enrichment.

**Five traps in these payloads, all live:**

1. **`HotelRating` changes type between methods** — `"ThreeStar"` (string enum) from
   `TBOHotelCodeList`, `3` (integer) from `HotelDetails`. Both must normalise to the same thing.
2. **`CountryCode` comes back lowercase** (`"ph"`) here, while `CountryList` returns `"PH"`.
3. **`CityName` is not the city you asked for.** Requesting Alcoy (`100834`) returns hotels whose
   `CityName` is *"Cebu City"*, and `HotelDetails` reports a third value in `CityId` (`114570`).
   TBO's city taxonomy is loose — key stored hotels on the **requested** city code, which is the
   one Search will need, and treat the returned names as informational.
4. **`Latitude`/`Longitude` are separate fields** in the code list, but the same data arrives as a
   single `Map` string (`"9.68621|123.50438"`) from `HotelDetails`. §14 documents only `Map`.
5. **`Attractions` is an array**, not the String §14 declares; `Image` (singular) is an empty
   string beside the populated `Images` array.

**A bad hotel code does not poison a batch** — `HotelDetails` with one valid and one bogus code
answers `200` with a single record, so enrichment can skip failures without splitting the batch.

## 10. BookingDetailsBasedOnDate (§15)

`{ "fromdate": "2026-08-01", "todate": "2026-08-31" }` — max **60 days** — returns a flat list of
bookings with `BookingId, ConfirmationNo, BookingDate, Currency, AgentMarkup, AgencyName,
BookingStatus, BookingPrice, TripName, TBOHotelCode, CheckInDate, CheckOutDate,
ClientReferenceNumber`. An ops/reconciliation feed: the one call that can find a booking we lost.

## 11. Enumerations (§17)

| Type | Values |
| --- | --- |
| `PaxType` | `Adult`, `Child` |
| `MealPlan` (request filter) | `All`, `WithMeal`, `RoomOnly` |
| `StarRating` | `OneStar` … `FiveStar` |
| `PaymentMode` | `Limit`, `SavedCard`, `NewCard` |
| `BookingType` | `Voucher` |
| `MealType` (response) | `All_Inclusive_All_Meal`, `Full_Board`, `Half_Board`, `Room_Only`, `BreakFast`, `Lunch`, `Dinner`, `BreakFast_Lunch`, `Breakfast_For_1`, `Breakfast_For_2` |
| `Booking Status` | `Confirmed`, `Cancelled`, `CancellationInProgress`, `CancelPending`, `CxlRequestSentToHotel`, `CancelledAndRefundAwaited` |

## 12. Where the spec contradicts itself

Recorded because TBO Air taught us this the expensive way — five refused Books, four of them
caused by trusting a doc page (`../tboair/02-current-implementation.md`). Treat each of these as
**verify against a real response before relying on it**.

| # | Contradiction | Consequence |
| --- | --- | --- |
| 1 | **`BookingStatus` values.** §17 lists `Confirmed`; the §10 sample returns **`"Voucher"`** and §15's sample returns **`"Vouchered"`** | Three spellings for one state. Map defensively, refuse to guess an unknown value |
| 2 | **`MealType`.** §6.2 says "Possible Values: `Breakfast_For_2`, `Breakfast_For_1`, `All_Inclusive_All_Meal`"; §17 lists ten | Never switch exhaustively on meal type |
| 3 | **`Supplements` shape.** §6.2 types it "List of Object", the §6.2.1 sample is a **list of lists** | Flatten one level defensively |
| 4 | **`CancelPolicies` shape.** §6.2 "Array" of objects; §7.2 "List of String Array" | Same — parse structurally, not by the declared type |
| 5 | **Money types.** `ExtraGuestCharges` / `RecommendedSellingRate` are typed decimal, sampled as strings | Cast at the boundary; keep money as decimal strings internally |
| 6 | **`HotelDetails` request key** is `Hotelcodes` (§14.1.1) while the parameter table says `Hotel Code` | Send the sample's spelling |
| 7 | **Duplicate field name.** §10.2 lists `AddressLine1` twice (the second is plainly `AddressLine2`) | Read both keys |
| 8 | The §6.1.1/§6.1.2 sample JSON has **unbalanced braces and smart quotes** | The samples are hand-edited; do not copy verbatim |

## 13. Open questions for TBO

1. ~~**Which test BaseURL is current?**~~ ✅ **Answered by a real call, not by TBO** —
   `https://api.tbotechnology.in/HotelAPI`, as the spec says. See §2.
2. **What is the live BaseURL** on our contract? The PDF redacts it; production uses
   `https://apiwr.tboholidays.com/HotelAPI`, which is what we have configured. Worth confirming
   before go-live rather than discovering at the first live call.
3. **What is our QPS limit** (the `429` threshold)? Chunked city searches will hit it first.
4. **Is there a balance/credit-limit read**, as TBO Air has? Nothing in this spec exposes one.
5. **Which `BookingStatus` strings can actually be returned** (§17 vs the samples)?
6. **Is there a certification matrix** for hotels, as there is for air (11 cases)?
