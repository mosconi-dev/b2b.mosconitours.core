# TBO Air — The Live Reference Implementation

There is a **second codebase that already books and tickets through TBO Air in production**:
`/home/mosconi-mike/b2b.philippineexplorer.com`. It is the system currently live.

That makes it the highest-quality evidence available about this API — better than TBO's own
documentation pages, which it contradicts in places. This doc records what reading it established.
Line references are to that repo, not this one.

> Its own flight documentation is worth reading directly: `_docs/06-flights.md` there covers the
> full pipeline, and `_docs/18-known-issues-and-risks.md` is candid about the defects repeated below.

---

## 1. It answers P1 — the identifier question

**`ResultId` is `ResultIndex`, and `TrackingId` is `TraceId`.** The live code proves it by wiring one
straight into the other:

| Step | Code | Effect |
| --- | --- | --- |
| Search | `Services/TboAir/SearchService.php:130-132` | stores `'resultIndex' => $result->ResultIndex` and `'trackingId' => $bodyResponse->TraceId` |
| Book | `Http/ApiClients/TboAir.php:1935` | sends `'ResultId' => …['apiData']['resultIndex']` |
| Book / Ticket | `Http/ApiClients/TboAir.php:1975`, `:2009` | send `'TrackingId' => …['apiData']['trackingId']` |

So their local name `trackingId` **is** TBO's `TraceId`, and it is submitted to Book under the name
`TrackingId`. The decisive detail is that Book reads the key **`resultIndex`**, which only the modern
`SearchService` produces — the legacy static search emits `resultId` instead. The live path is
therefore unambiguous:

```
modern SearchService  (TraceId + ResultIndex)
      └─> legacy static Book/Ticket  (TrackingId + ResultId)
```

**Mixing API generations across hosts is fine.** Their `config/app.php:277-304` is byte-identical to
our `config/tboair.php`: search/fare/SSR on `InternalAirService.svc`, Book/Ticket/GetBookingDetails on
the `api/v1` booking host. That exact combination issues real tickets today.

> ⚠️ **Two naming conventions live in that repo.** The legacy static client also contains its *own*
> search (`TboAir.php:386-391`) against a generation that returns `ResultId` + `TrackingId` directly.
> It is not the live path — their `CLAUDE.md` says plainly: *"Do not extend the fat legacy controllers
> or the static `Http/ApiClients/TboAir.php`; use `app/Services/TboAir/*`."* Don't be misled by it.

## 2. Ticket is the Book payload plus a PNR

The most useful structural finding, and much simpler than TBO's method pages suggest
(`TboAir.php:2024-2048`):

```php
if (empty($bookData)) {          // LCC — Ticket books and issues in one
    $payload = self::createBookPayload($data, $credentials);
    $payload['PNR'] = null;
} else {                         // non-LCC — reuse the exact payload Book was given
    $payload = $bookData['payload'];
    $payload['PNR'] = $bookData['response']->PNR;
}
$payload['ConfirmPriceChangeTicket'] = false;
```

**One payload builder serves both calls and both carrier types.** Phase 4.1 should be built that way
rather than as two request shapes.

Their client treats the call as failed only when
`!$body->IsSuccess && !in_array($body->Status, [1, 2, 5, 8])` — i.e. Successful, Failed, BookedOther
and InProgress all pass through to the status mapping rather than erroring at the transport layer.

## 3. What the Book payload actually contains

Confirms everything in [`01-tbo-api-reference.md`](01-tbo-api-reference.md) §5.1, and confirms the
need for `quote_raw`:

- **Raw segments are echoed verbatim.** `TboAir.php:1319` pushes `$segment['fullApiSegment']` — the
  untouched segment object kept from the search response. They preserved the raw supplier data for
  exactly the reason we added `quote_raw`; a UI-shaped transform cannot rebuild it.
- **Per-passenger fares are divided by head count** (`:1329-1357`): `totalFare / paxCount`,
  `baseFare / paxCount`, `Tax`, `Othercharges`, `AgentMarkup`, `ServiceFee` — split out per pax type
  (1/2/3) from the fare breakdown, exactly as TBO documents.
- **Identity documents** (`:1372-1390`): `IdType` is `2` domestic / `1` international, with
  `IdDetails{PaxId, IdType, IdNumber, ExpiryDate, IssuedCountryCode, IssueDate}`.
- **Hardcoded values** worth knowing: a single **company address for every passenger**
  (`:1362`), `PointOfSale => 'PH'`, `RequestOrigin => 'Philippines'`, `BookingMode => 5`,
  `SupplierGroupId => 5`, `TboAirBookingSourceId => 0`, `IsVATApplicable => true`,
  `LastVoidDate => '0001-01-01T00:00:00'`, `MiniFareRules => [[]]`, and `UserData => $leadPax`.

Our P3 fan-out covers the same ground and actually collects the address instead of fixing it.

**`GetBookingDetails`** (`TboAir.php:2057-2078`) takes exactly `PNR` + `TokenId`, matching the doc page.

## 4. Orchestration — `ProcessTicketJob`

Ticketing is asynchronous: `finalizeBooking` commits the transaction rows, then queues
`ProcessTicketJob` (`app/Jobs/ProcessTicketJob.php:47-117`).

```
if (! isLcc)  TboAir::book($data)
TboAir::ticket($data, $bookResponse)
   └─ map Status via [1 => 2, 2 => 5, 5 => 3, 8 => 5]   (TBO status → their transaction status)
      unknown → throw
      transaction.reloc = PNR; transaction.status = mapped
      if (mapped == 2) FlightTicketPurchased::dispatch(...)   // the wallet debit
```

Only TBO status **1 (Successful)** reaches the debit. Note `Failed` (2) and `InProgress` (8) collapse
into the same bucket (5).

## 5. Defects not to copy

Their own known-issues doc flags most of these. They are listed here because Phase 4.1 will be
tempted to mirror this code.

| Defect | Where | Why it matters |
| --- | --- | --- |
| **`GetBookingDetails` is never called in the ticketing pipeline** | only `BookingHistoryController:568`, an operator screen | TBO **mandates** it after every state-changing step. It is the only authoritative status read, and without it an ambiguous outcome cannot be reconciled. |
| **A failed Book does not abort** | `ProcessTicketJob:61-68` | Status is set to 0 and execution falls through to `ticket()` with `$bookResponse = null` — which makes Ticket take the **LCC** branch and attempt to issue with `PNR = null`. The `throw` is commented out. |
| **No idempotency guard** | `ProcessTicketJob` | Nothing stops one transaction being ticketed twice. |
| **Wallet written from a stale snapshot** | `FinalizeBookingService` → `FlightTicketPurchased` | `balanceAfter` is computed at finalize and **absolute-set** minutes later in the job, overwriting any debit that landed in between. |
| **No TBO balance check** | nowhere | Nothing reads `GetAvailableBalance`; a ticket can fail on supplier funds with no warning. Our P4 is new capability, not a port. |
| **ReleasePNR / Refund never called** | — | Dormant there too, and their config carries the same unverified `Booking/RefundApi` URL. **This question is still open.** |

Our design already avoids most of these: the wallet debits under lock against an authoritative ledger,
`BookingStatus` is a guarded state machine, and the plan requires `GetBookingDetails` reconciliation.

## 6. Two corrections to our own implementation

Both are things the live system does differently, and it is the one with production evidence.

### 6.1 The stale-session signal may be `ResponseStatus == 4`, not `ErrorCode 6`

`SearchService.php:45`:

```php
if ($bodyResponse->ResponseStatus == 4) {   // token invalid → clear cache, re-auth, retry once
```

Our `TboAirService::withReauth()` keys on `TboAirException::isAuthError()` / `ErrorCode 6`. Both
signals may exist, but a production system chose 4. **Verify our error mapping catches it** — if it
does not, we fail where we should self-heal. Worth a look before 4.1.

### 6.2 Their token TTL is 12 hours

`SearchService.php:565`: `$ttlHours = 12; // 12 // 24` — the trailing comment is someone else's open
question about the same contradiction we hit. Refresh is wrapped in a `Cache::lock('tboair-auth-lock')`
so concurrent workers cannot stampede the auth endpoint.

That makes **four** figures for token validity: 12h (guide **and production**), 20h
(`GetAgencyBalance` page), 24h (TBO's meeting), 23h (our TTL). Production runs the most conservative
one. See [`01-tbo-api-reference.md`](01-tbo-api-reference.md) §4.

> Our token cache has **no such lock**. Concurrent requests that all miss the cache can each fire an
> Authenticate — which matters more if the "one token per day" rule is ever real.
