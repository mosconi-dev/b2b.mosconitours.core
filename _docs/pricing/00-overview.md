# Pricing — markup strategies across products

How a supplier's net rate becomes the price an agency pays. Covers **local and international flights
and hotels**, a **platform default** strategy owned by the main office, and a **per-agency** strategy
that overrides it. Proposed namespace: `App\Services\Pricing`.

**This document is the investigation that preceded the build, kept as the record of what existed
before pricing and why it was designed this way.** For what was actually built, and for every
decision that has moved since, read [`01-architecture.md`](01-architecture.md) — it is the current
one. Where the two disagree, this file is the older.

## Design in one paragraph

**The supplier's number is never overwritten.** A booking records what the supplier charged us
(`net_amount`), what we added (`markup_amount`), and their sum (`total_amount`) — the wallet is
debited the sum, TBO is always sent the net. Markup comes from a **strategy**: an ordered set of
rules resolved **agency → parent office → platform default**, matched on what the search already
knows (product, local vs international, airline, star rating, refundability, LCC, dates, amount).
Rules are data and are edited in the admin area; the **snapshot of which rule fired** is copied onto
every booking, because a rule that has been edited twice cannot explain a price it set a year ago.

## Why this document exists

Markup was deliberately deferred, twice, with a note to design it once rather than per-product:

> **Markup / selling price.** Core sells flights at the supplier fare today; there is no markup
> engine (`config/rbac.php` has `markup` and `markup.office` as stubs and nothing implements them).
> Hotels must **not** grow a private one — the live system's per-head hotel markup
> ([`02`](../tbohotel/02-live-reference-implementation.md) §4.7) is the cautionary example. Design
> markup once, across products, in its own project.
>
> — [`tbohotel/03-implementation-plan.md`](../tbohotel/03-implementation-plan.md), *Scope and non-goals*

This is that project. Two anchors already exist in the codebase and were put there for it:

- **`config/rbac.php:257-272`** — `markup` (view, edit) and `markup.office` (view, edit), both
  `route => null`, grouped under **Markups**. The split is exactly the two tiers described here:
  `markup.office` governs the platform default, `markup` governs an agency's own.
- **`agencies.parent_id`** — *"records which office an agency reports to, for **reporting and markups
  only**. It grants nothing."* ([`rbac/01-agencies.md`](../rbac/01-agencies.md)). The reporting tree
  was built to be a markup inheritance chain, and §4 finally uses it.

## 1. Identifying the service — local vs international

Both products can already answer this from data in hand. Neither surfaces the answer.

### 1.1 Flights — the data is there, and there are three classifiers

`ItineraryMapper` writes a country code onto both ends of every leg —
`'country' => data_get($leg, "$side.Airport.CountryCode", '')` (`ItineraryMapper.php:183`) — and
`FlightOffer.trips` carries it verbatim into search results. **Every offer in the results list can be
classified with no extra API call.**

What exists today are three separate implementations of the same question:

| Where | Compares | Against | Used for |
| --- | --- | --- | --- |
| `TboAirService::isDomestic()` `:380` | search-input IATA codes | `config/airports.php` rows whose `country === 'Philippines'` — a **name**, on a curated 36-airport list | sets `IsDomestic` on the Search request |
| `FareQuote::isDomestic()` `:129` | each leg's `origin.country` / `destination.country` | `config('tboair.point_of_sale', 'PH')` — a **2-letter code** | passport vs government ID at booking |
| `TboBookPayload::isDomestic()` `:391` | each segment's `Origin.Airport.CountryCode` | `config('tboair.point_of_sale', 'PH')` | `PassengerIdType` (1 international / 2 domestic) on Book |

The last two agree. The first answers from a different source in a different format, and its curated
list is the weakest of the three — an airport nobody has added yet reads as international. That is
harmless while the answer only decorates a request, and **not harmless once money depends on it**: a
flight priced domestic in the results list and international at checkout is a support ticket.

`FareQuote`'s convention is the one to keep and is already documented at `FareQuote.php:120-126` — a
missing country code makes the answer unknowable, and an unknown route reads as **international**,
because that is the direction that fails safe.

### 1.2 Hotels — known per property, not currently emitted

`Hotel` catalogue rows carry `country_code` (2-char) and `city_code`, and `HotelOffer` holds the
joined `?Hotel` (`HotelOffer.php:24`). `HotelBooking` stores `country_code`, `city_code` and `city`,
so **historical bookings are classifiable retroactively** — a backfill can label every past booking
without touching the supplier.

The gap is that `HotelOffer::toArray()` (`:83-100`) emits name, address, rating, coordinates and
thumbnail, and neither country nor city. Nothing downstream of a search knows where the hotel is.

The edge case is a **catalogue miss**: `$hotel` is null, `name()` falls back to `"Hotel {$code}"`,
and country is unknown. Same convention as flights — unknown reads as international.

> A hotel search is per-city or per-property, so in practice every offer in one result set shares one
> answer. Classify per offer anyway: it costs nothing, it survives a mixed result set, and it keeps
> the flight and hotel paths shaped the same.

### 1.3 One enum, one resolver — **built, Phase 1**

```php
enum TravelScope: string { case Domestic = 'domestic'; case International = 'international'; }
```

`App\Enums\TravelScope` and `App\Support\TravelScopeResolver`, reading the point of sale from a new
product-neutral `pricing.point_of_sale` (falling back to `TBOAIR_POINT_OF_SALE`, so no deployment
had to change). All three implementations in §1.1 now delegate to it.

> **It landed in `App\Support`, not `App\Services\Pricing` as first proposed.** This is a geography
> question with two consumers — the identity-document rules, which have asked it since before pricing
> existed, and pricing, which will. Putting it under Pricing would make `TboAirService` reach into the
> pricing namespace to decide whether to ask for a passport. It sits beside `Airports` and
> `Countries`, and Pricing will depend on Support rather than the other way around.

Entry points, one per shape the callers actually hold:

| Method | Caller | Reads |
| --- | --- | --- |
| `forAirports(array $iata)` | `TboAirService` search request | the curated list — the one moment TBO has told us nothing yet |
| `forLegs(array $legs)` | `FareQuote`, `FlightResultTransformer` | `origin.country` / `destination.country` from `ItineraryMapper` |
| `forSegments(array $raw)` | `TboBookPayload` | `Origin.Airport.CountryCode` on the raw `quote_raw` segments |
| `forCountryCode(?string)` | `HotelOffer` | the catalogue row's `country_code`; null → international |

`config/airports.php` gained a `country_code` per entry so the curated list and the supplier compare
the same thing. A test asserts every entry carries one **and** that it agrees with the display
`country` — an entry added without it would classify a Philippine airport as international.

`FlightOffer` and `HotelOffer` each carry a `scope`, emitted in `toArray()` as `scope` +
`scopeLabel`; `HotelOffer` also emits `countryCode` and `cityCode`. `FareQuote` carries the enum and
serializes **both** `scope` and the older `isDomestic` boolean — that array is persisted to
`bookings.quote` and `ETicket` reads `isDomestic` off it, so dropping the key would blank the
document label on every booking already taken.

## 2. Where price is decided today — and the one thing that breaks

Confirmed by search: **no markup, commission, margin or service-fee concept exists anywhere in the
codebase.** `bookings.total_amount` *is* the supplier fare, and `ChargesWallet::chargeWallet()`
debits exactly that figure. Markup starts from zero.

Six places compute or carry a price:

| # | Product | Where | What it is |
| --- | --- | --- | --- |
| 1 | Flight | `FlightResultTransformer::mapFare()` `:96` | the results-list price (`offeredFare` via `FareTotal`) |
| 2 | Flight | `BookingService::createFromQuote()` `:74` | `offeredFare + ancillaryTotal` → stored **and charged** |
| 3 | Flight | `BookingService::applyAncillaries()` | SSR baggage/meal spend, summed into `ancillary_total` |
| 4 | Hotel | `RoomOffer.totalFare` → `HotelOffer::lowestFare()` | the results and rooms pages |
| 5 | Hotel | `HotelBookingService::createFromQuote()` `:84` | `$quote->totalFare()` from PreBook → stored **and charged** |
| 6 | Hotel | `HotelBookingService` `:73`, `PreBookResult::priceChanged()` | the price-change gate |

Two of these are traps.

**The price-change gate (#6).** PreBook re-prices, and the gate compares what the agent was shown
against what came back. Apply markup to one side and not the other and **every hotel booking trips a
false *"The hotel re-priced this room from X to Y"***. The gate must compare net against net, or sell
against sell — never one of each.

**The hotel Book payload — this one is an outright breakage.** `TboHotelBookPayload.php:53`:

```php
// PreBook's figure, which is what the agency was charged. Search's price
// has no standing by now and TBO refuses a mismatch.
'TotalFare' => (float) $booking->total_amount,
```

The comment states the rule: **TBO refuses a mismatch.** The moment `total_amount` becomes a sell
price, that field carries our margin back to the supplier, TBO compares it to its own number, and
every hotel booking fails at Book. This is not a subtle risk; it is the first thing that would break.

The flight side is accidentally safe today — `TboBookPayload` sends `'Fare_BE' => $fare` read from
`quote_raw`, never from the booking row, so a marked-up `total_amount` cannot leak into it. That
asymmetry is luck, not design, and §3 removes the need to rely on it.

> TBO's own `Fare` block has an **`AgentMarkup`** field (`tboair/01-tbo-api-reference.md` §5.1). That
> is the markup configured on *our* TBO profile and is already inside the fare they quote us. It is
> the supplier's number, echoed back untouched like the rest of the block. **Ours never goes there.**

## 3. Data model

### 3.1 `bookings` — split net from sell

| Column | Meaning |
| --- | --- |
| `net_amount` | what the supplier charges us. **This is what goes to TBO** and what refunds reconcile against. Today's `total_amount`. |
| `markup_amount` | what we added. Zero is a valid, common answer. |
| `total_amount` | `net + markup` — the sell price. **This is what the wallet is debited** and what the agent, the e-ticket and the voucher show. |
| `pricing_snapshot` (json) | the strategy and rule that fired, their versions, the inputs they matched on, and the arithmetic. |

Keeping `total_amount` as the sell price means the wallet, the bookings list, `HotelVoucher.php:230`
and `ETicket.php:302` keep reading the column they already read, and the migration backfills
`net_amount = total_amount, markup_amount = 0`. Every existing row stays correct.

The snapshot is **not optional**. Rules are editable data. In a year someone will ask why `MT-XXXXXXXX`
was priced the way it was, and the rule will have been changed twice since. A booking that cannot
explain its own price is a booking that gets refunded on argument rather than on fact.

`ancillary_total` stays as it is — it is a component of net, not a third total.

### 3.2 `pricing_strategies`

`id`, `agency_id` (**nullable — NULL is the platform default**), `name`, `is_active`,
`effective_from` / `effective_to` (both nullable), `timestamps`, `softDeletes`.

**The default is a row, not a config value.** `agency_id = NULL` follows the convention `roles`
already set for platform-level records, which means the default is edited in the same screen, gated
by the same permissions, and written to the same audit log as every agency's own. A default that
lives in `config/` is a deploy, not an operation.

### 3.3 `pricing_rules`

`strategy_id`, `product` (`flight` \| `hotel`), `scope` (`domestic` \| `international` \| `any`),
`matchers` (json, §5), `calc_type` (§6), `value`, `min_markup`, `max_markup`, `basis`, `rounding`,
`priority`, `is_active`.

> **Superseded.** This section originally proposed **first match wins, by `priority`** within a
> strategy, arguing that an accumulating engine makes "why is this fare 40% up?" unanswerable.
>
> **The business chose cumulative on 15 August 2026.** Every rule that matches contributes and the
> contributions sum: a base rate, a service fee and a surcharge are three rules, and a booking they
> all match pays all three. The concern above was answered by making the price explain itself
> instead — every rung that fired is recorded against the booking and named in the preview.
>
> `priority` survives only as the order rules are applied in, which no longer changes a total.
> See [`01-architecture.md`](01-architecture.md) §3.

## 4. Resolution — cumulative, not override

> **Superseded by [`01-architecture.md`](01-architecture.md).** This section originally proposed an
> **override** chain — *agency → parent office → platform default*, first match wins. That is wrong
> for this business. Pricing is **cumulative**: Main Office + Agency, each level adding its own
> contribution on top of the previous one. The Agency's markup does not replace the Main Office's,
> it stacks on it.
>
> This was later taken further: contributions are cumulative **within** a level as well as between
> levels. The "not a sum of every matching rule" argument in §3.3 no longer holds — see the note
> there.
>
> See [`01-architecture.md`](01-architecture.md) §3 for the resolution algorithm and §7 for the
> settlement question this raises.

Walking `parent_id` is what that column was reserved for. It grants no permission and never has; this
is the reporting-and-markups use it was documented for. The walk needs the same
self-and-descendant guard `AgencyService` already applies when setting a parent, so a cycle cannot
hang the resolver.

**Zero is a real terminus, not a failure.** No strategy at a level means that level adds nothing,
which is exactly today's behaviour — so the engine can ship, contribute nothing, and change no price.

## 5. What a rule may match on

Every dimension below is **already in the DTOs** at the moment pricing runs. Nothing here needs a new
supplier call.

**Flights** — `TravelScope` · airline code (`FlightOffer.airlineCode`) · cabin (`cabin`) ·
**LCC vs GDS** (`isLcc`) · refundable (`isRefundable`) · route / origin / destination · stops.

> `isLcc` earns its place. LCC fares are thin, and a flat percentage that is reasonable on a
> full-service international ticket prices a domestic budget seat out of the market. Any first rule
> set will want to separate them.

**Hotels** — `TravelScope` · country · city · star rating (`hotel.rating`) · refundable
(`RoomOffer.isRefundable`) · meal plan (`mealType`) · specific hotel code · nights (`SearchInput::nights()`).

**Both** — agency · agency type (`main_office` \| `outlet` \| `itp`, already an enum) · travel-date
window (seasonality) · booking-date window (promotions) · net-amount band.

## 6. Calculation types

| Type | Shape | Where it fits |
| --- | --- | --- |
| **Percentage** | `net × value%` | the default for hotels and full-service fares |
| **Fixed per booking** | flat amount | thin-margin LCC fares |
| **Fixed per pax** (flight) / **per room-night** (hotel) | flat × count | the industry norm for air |
| **Percentage, floored and capped** | `clamp(net × value%, min, max)` | *"10%, at least ₱500, at most ₱3,000"* — covers most real cases on its own |
| **Tiered by net band** | a percentage per amount band | high-value long-haul |
| **Percentage + fixed** | both | a percentage margin plus a fixed service fee |
| **Zero / pass-through** | explicitly nothing | negotiated corporate and staff rates — an *explicit* zero, so nobody wonders whether a rule was missing |

**Rounding** is a property of the rule, not a global: up to the nearest 1 / 10 / 50 / 100. A price
that ends in ₱7,850 reads as deliberate; one that ends in ₱7,847.63 reads as arithmetic.

> **Per room-night, not per head.** The live system multiplies hotel markup by head count
> (`TboHotel.php:182-187`, [`02`](../tbohotel/02-live-reference-implementation.md) §4.7): *"A hotel
> rate is per room per night, not per person — the fee scales on the wrong axis."* Two adults in one
> double room pay one room rate and, under that model, two markups. This table is the fix; do not
> reintroduce a per-person option for hotels.

## 7. Decisions to settle before any code

Each changes the schema or the arithmetic, so each is cheaper now than in Phase 3.

**D1 — How many markup layers?** **Answered: two, cumulative** — Main Office + Agency
([`01-architecture.md`](01-architecture.md)). Users are not a pricing level and own no strategy.
What it left open is [`01`](01-architecture.md) §7 and §11-C3: **which rung the wallet is actually
debited**. Markup is recorded as a per-level ledger (`booking_price_layers`), not one column.

**D2 — Markup basis: net total, or base fare only?** Marking up on taxes inflates long-haul badly,
where tax can approach half the ticket; base-fare-only is the industry norm. `FareQuote` exposes both
(`price.baseFare`, `price.tax`, `FareBreakdown` per pax type), so this is a `basis` column on the
rule, not a fork in the code — but the **default** value needs deciding.

**D3 — Are ancillaries marked up?** SSR baggage and meals flow through `applyAncillaries()` into
`ancillary_total` and then into the charged total. Marking up a ₱1,200 baggage add-on is defensible;
doing it silently is not.

**D4 — Per pax or per booking, on flights?** `FareBreakdown` carries per-pax-type rows, so both are
available. Per pax is the norm; a family of five is where the difference becomes visible.

**D5 — Does the agent see the markup?** *Net-plus* (the agent sees only the sell price, our margin is
invisible) or *commission* (the agent sees net and their own margin). The wallet debiting the total
implies net-plus. Whichever it is, `pricing_snapshot` records the truth either way — the decision is
about what the UI, the e-ticket and the voucher reveal.

**D6 — Is markup refunded on cancellation?** Today *"cancellation is refunded in full… there is no
airline-penalty or service-fee model"* ([`wallet/00-overview.md`](../wallet/00-overview.md)). Once
markup exists, a full refund gives back our margin too. Common practice is to retain it, or retain it
in part. Splitting `markup_amount` out is what makes any of those policies expressible at all — the
refund path reads the original ledger entry, so this needs deciding alongside it.

**D7 — Currency.** Everything is PHP and multi-currency is explicitly out of scope for hotels. Markup
must **not** become the place FX quietly enters: a rule's amount is in the wallet currency, and a
supplier answering in anything else is already a guarded failure.

## 8. Cross-cutting rules

1. **The supplier is always sent net.** `TboHotelBookPayload` reads `net_amount`. `TboBookPayload`
   keeps reading `quote_raw`. No payload ever reads `total_amount`.
2. **Markup is applied after the cache, never inside it.** `FlightSearchCache` and `HotelSearchCache`
   are per-user, keyed on environment plus a search fingerprint, with TTLs measured in minutes. Bake
   markup into the cached payload and a rule edited at 10:00 leaves old prices live until the TTL
   expires. **The cache holds net; the engine runs on the way out.**
3. **The same rule must fire at search and at booking.** A price shown and a price charged that
   disagree is the worst failure this module can have. Both paths call one engine with one context;
   the booking path re-resolves rather than trusting anything the browser sends back — the same
   reason `BookingService` re-prices through FareQuote and `HotelBookingService` through PreBook.
4. **The price-change gate compares like with like.** §2, trap one.
5. **Money is bcmath on decimal strings, never floats** — the wallet's rule, and markup arithmetic is
   the same money.
6. **Every strategy and rule change is audited** through the existing `AuditLogger`, so it inherits
   agency scoping: `pricing.strategy_created`, `pricing.rule_updated`, and so on.
7. **RBAC-gate everything** using the stubs that already exist: `markup.view` / `markup.edit` for an
   agency's own, `markup.office.view` / `markup.office.edit` for the platform default. Both need a
   `route` and their `enabled` flag flipped; the permission rows already sync.

## 9. Phase plan

> **Superseded by [`01-architecture.md`](01-architecture.md) §9**, which restates this as five
> phases for the two-level model — separating the engine (which ships contributing zero) from the
> commit that wires it into search and booking. The shape below is unchanged: classification first,
> schema second, price movement only after both.

Each phase is independently shippable and tested, and lands as its own commit. **The first two change
no price at all**, which is what makes this safe to do incrementally.

### Phase 1 — Classification, display only

`TravelScope` enum and one resolver, replacing all three `isDomestic()` implementations (§1.1).
`scope` on `FlightOffer` and `HotelOffer`, emitted in `toArray()`, shown as a chip on the results
cards. `country_code` and `city_code` added to `HotelOffer::toArray()`.

No money moves. This proves the classification is right *before* anything is priced on it, and it is
independently useful — an agent filtering international hotels wants this regardless.

### Phase 2 — Schema, still zero markup

`net_amount`, `markup_amount`, `pricing_snapshot` on `bookings`; backfill `net = total, markup = 0`.
`TboHotelBookPayload` switches to `net_amount`. Refund and cancellation-charge maths point at net.
`scope` stored on the booking at creation, backfilled for history from `hotel_bookings.country_code`
and `quote.trips`.

Prices are now structurally correct with markup fixed at zero. Nothing an agent sees changes.

### Phase 3 — The engine and the default strategy

`PricingEngine::quote(PricingContext): PriceBreakdown` — one entry point, called from all six sites
in §2. `pricing_strategies` + `pricing_rules`. **Percentage-floored-and-capped only**; the other calc
types wait. One platform default strategy. Admin screen under **Markups**, gated by
`markup.office.*`. Audit events.

This is the phase that first moves money. Ship it with the default strategy empty, then set the first
rule as an operation rather than a deploy.

### Phase 4 — Per-agency strategies

`agency_id` strategies, the parent-office walk (§4), the remaining calc types, effective dates, the
`markup.*` screen for an agency's own. Whatever D1 decided about a second layer lands here.

### Phase 5 — Margin reporting

`markup_amount` summed by agency, product, scope and month. This is the number the office actually
runs on, and it exists as a column from Phase 2 onward — the reporting is a read, not a rebuild.

## 10. Out of scope

- **Multi-currency and FX.** D7. Markup is quoted and charged in the wallet currency.
- **Supplier-side markup.** TBO's `AgentMarkup` is configured on our TBO profile and is already
  inside the net we are quoted. It is not ours to set from here.
- **Customer-facing quotes and invoices.** If D1 lands on two layers, the agency's client price is a
  displayed and printed figure. Generating a client-facing quote document is a separate project.
- **Discounts and promo codes.** A negative markup is a different mechanism with different
  authorization — do not smuggle it in as a rule with a negative `value`.
