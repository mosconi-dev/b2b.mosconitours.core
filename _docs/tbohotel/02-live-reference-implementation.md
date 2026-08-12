# TBO Hotel — The Live Reference Implementation

`b2b.philippineexplorer.com` has been booking hotels through TBO Holidays in production for years.
As with flights, that makes it better evidence than the PDF about what the API actually accepts —
**and** a catalogue of what not to repeat. Line references are to that repo.

> Its own documentation is worth reading first: `_docs/07-hotels-tbo.md` (the module) and
> `_docs/18-known-issues-and-risks.md` (candid about the defects below).

Structurally it is the *less* refactored of its two supplier modules: `HotelsController` is 999
lines, the static `app/Http/ApiClients/TboHotel.php` is 483, and only two thin services exist
(`FinalizeBookingService`, `EwalletValidationService`). Their own `CLAUDE.md` says not to extend
either. So this document takes the **facts** from it and leaves the **shape** behind.

---

## 1. What it proves about the API

| Fact | Evidence |
| --- | --- |
| **Basic Auth, no token** | `TboHotel.php:35` — `Http::withBasicAuth($username, $password)` on every call, nothing cached |
| **`Accept-Encoding: gzip, deflate, br` and a 60 s timeout** work | `:36-39`. Same header trick as TBO Air, where the wrong `Accept` hangs the gateway |
| **`Status.Code == 200` is the success test** | `:59`, and repeated at every call site |
| **`PaymentMode: "Limit"` + `BookingType: "Voucher"` is the live booking mode** | `:362-364`, `:407-408` — cards are never used |
| **`CustomerDetails` is one entry per room**, each `{ CustomerNames: [{Title, FirstName, LastName, Type}] }` | `:387-398` |
| **Search `Supplements` really is double-nested** | `:242-243` — a `foreach` inside a `foreach` before reaching the objects. Confirms §6.2.1 over §6.2 |
| **`CancelPolicies[].Index` is genuinely optional** | `:216-228` — policies without one are bucketed as `'all'` |
| **The catalogue is local, and search resolves against it** | `:110-119` — city → `tbo_hotels` → comma-joined `hotel_code`s |
| **Cancel takes only `ConfirmationNumber`; BookingDetail takes either it or `BookingReferenceId`** | `:440-450`, `:472` |

## 2. The pipeline as built

```
1 SEARCH       POST /hotels/search            → TboHotel::searchHotels     (list, chunked 30/page into session)
2 ROOMS        POST /hotels/get-room-availability
3 SELECT ROOM  POST /hotels/select-room       → stores the SEARCH room in session
4 PAX INFO     POST /hotels/pax-info/submit   → …then calls PreBook
5 REVIEW       markup edit
6 FINALIZE     POST /hotels/finalize-booking  → TboHotel::bookHotel
```

Session keys: `bookingSessionId`, `hotelSearchQuery`, `hotelSearchResults`, `selectedHotelRoom`,
`hotelPaxInfo`. Everything between search and book lives in the PHP session — nothing is durable
until finalize, so a booking cannot be resumed, audited mid-flight, or reconciled if the session
dies.

Persistence at finalize is **nine tables**: `hotel_booking_searches`, `…_search_rooms`,
`…_rooms`, `…_room_types`, `…_room_rates`, `…_room_supplements`, `…_room_cancellation_policies`,
`…_room_rates_conditions`, `…_pax_infos`, `…_pax_info_per_room`. A fully normalised copy of one
supplier response, written once and read almost never.

## 3. The catalogue sync is half-built — and the half that matters is missing

`ManagementController::mapTboHotelDestinations` (`:534-593`) syncs **countries and cities only**:

```php
$countries = TboHotel::mapCountries();          // CountryList
foreach ($countries->CountryList as $country) {
    if (TboCountry::where('code', $country->Code)->exists()) continue;   // skip = never updated
    $cities = TboHotel::mapCities($country->Code);                       // CityList
    …
}
```

**Nothing in that codebase ever writes `tbo_hotels`.** `hotel_code_list` and `hotel_details` are
configured in `config/app.php:324-325` and called from nowhere; `TBOHotelCodeList` is not
configured at all. The hotel catalogue — the table every search depends on — was loaded by hand
and has no refresh path. Grep confirms it: the only references to the model are relations and
reads.

Three consequences worth naming, because they are the reason Phase 2 of our plan is its own phase:

1. **New TBO properties never appear**, and closed ones never disappear.
2. `continue`-on-exists means even countries and cities are **insert-only** — a renamed city keeps
   its old name forever.
3. A failed `CityList` mid-loop **aborts the whole run** with a 422 and no resume point
   (`:556-561`).

## 4. Where it diverges from the spec — the defects not to copy

Each of these is a decision our implementation should make differently, and the reason is in the
spec, not in taste.

### 4.1 A price change is treated as an error, and only the first room is checked

```php
// HotelsController.php:513-520
$prebook = ApiTboHotel::preBook($selectedHotelRoom['roomAvailability']['bookingCode']);
if (!$prebook || $prebook->HotelResult[0]->Rooms[0]->TotalFare != $selectedHotelRoom[…]['price']['total']) {
    return ['success' => false, 'message' => "Hotel room is not available anymore…", 'isPriceChanged' => true];
}
```

Two problems. A moved price sends the agent back to a fresh search instead of showing
**old vs new** and letting them accept — the pattern our flight wizard already implements. And the
comparison reads `Rooms[0]` only, so on a multi-room booking a change in rooms 2..n passes
unnoticed while the *old* total is carried forward.

### 4.2 PreBook's binding data is discarded

Only `RateConditions` is kept (`:521`). PreBook's **`CancelPolicies` and `Supplements` are
dropped**, and the search-time copies are what gets stored and shown — directly against §18's
*"Cancellation Policy and Norms received in the PreBook response will be considered as final"*.

### 4.3 Book sends the search price

```php
// TboHotel.php:404
'TotalFare' => $data['selectedHotelRoom']['roomAvailability']['price']['total'],
```

`price.total` is the **search** total. Given §4.1 rejects any mismatch this is usually equal — but
it is equal by accident, and it is the wrong source.

### 4.4 The mandatory 120-second reconciliation never happens

On a false/timeout response `bookHotel` returns `['unconfirmed' => true]` (`:414-420`), the
controller writes `statusId = 0` and stops. There is no delayed `BookingDetail` call, so a booking
that TBO *did* create sits unknown until a human notices. §10 makes that call mandatory. The
comment on the code is honest about it: *"User need to confirm with resa if any booking is
confirmed."*

Compounding it, `BookingReferenceId` is the **session id** (`:403`) — a value generated per search,
not per booking, and not durable.

### 4.5 The supplier call is inside `DB::transaction`, and the wallet debit is outside it

```php
// HotelsController.php:722
$result = DB::transaction(function () use ($data) {
    $apiResponse = ApiTboHotel::bookHotel($data);      // ← external HTTP, up to 120 s
    …nine tables of inserts…
});
if ($result['statusId'] == 1) {                        // ← outside
    $agency->update(['e_wallet_balance' => $data['balanceAfter']]);
}
```

Both halves are wrong in opposite directions: an insert failure rolls back the DB while the hotel
stays booked at TBO (an orphaned supplier booking), and a crash after commit leaves a confirmed
booking nobody paid for. `balanceAfter` is a **stale absolute** computed minutes earlier, so a
concurrent booking or top-up is silently overwritten. Their own issues doc lists all three (#2,
#3, #4).

### 4.6 A city search silently shows a fraction of the city

```php
// TboHotel.php:111
$hotels = ModelsTboHotel::where('city_id', $inputs['location'])->limit(100)->get();
```

`limit(100)` with no ordering. For any city with more than 100 mapped properties the agent sees an
arbitrary hundred and has no way to know. This is the single biggest functional gap, and it is a
direct consequence of §6's `HotelCodes` requirement never being designed around.

### 4.7 Other things to leave behind

| Issue | Where | Note |
| --- | --- | --- |
| Test endpoints are plain **HTTP** | `config/app.php:317-325` | Credentials in Basic Auth over cleartext; their issues doc #12 |
| Full search results held in the **session** | `HotelsController:165-167` | Hundreds of KB per user per search, chunked 30 at a time |
| No idempotency guard on finalize | `HotelsController:707` | Nothing stops a double submit booking twice |
| `IsDetailedResponse: true` on every search | `TboHotel.php:156` | Against §18's recommendation, on the largest call in the flow |
| Markup multiplied by **head count** | `TboHotel.php:182-187` | A hotel rate is per room per night, not per person — the fee scales on the wrong axis |
| Booking currency is whatever TBO returns | `:344` | No conversion, no guard that it matches the wallet currency |

## 5. What it gets right, and we should keep

- **Basic Auth + `Accept-Encoding` + 60 s timeout** as the transport baseline (we will split the
  timeout per method, per §4).
- **Per-room occupancy arrays** driven by a `no_of_rooms` loop, with children's ages flattened
  across rooms and re-associated on submit (`TboHotel.php:126-147`) — a fiddly mapping, correct
  there.
- **Bucketing `CancelPolicies` / `Supplements` by `Index`, with an `'all'` bucket** for entries that
  carry none, then sorting policies by `FromDate` (`:216-236`). That is exactly the right reading of
  §6.2 and worth porting as a small pure helper.
- **`mandatory_tax` → "Mandatory Tax"** relabelling (`:246`) — TBO returns machine strings in a
  guest-facing field.
- **Treating a `false` API response as *unconfirmed*, never as *failed*** (`:414-420`). The instinct
  is right; only the follow-up is missing.
- **Sorting results by cheapest room by default** (`HotelsController:158-163`).
- **A voucher PDF** rendered from stored data (`booking_history.hotel-voucher`), so a guest at a
  front desk never depends on TBO being up.
