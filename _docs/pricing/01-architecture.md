# Pricing — architecture and domain model

The design for **cumulative two-level markup**: Main Office + Agency, applied on top of a supplier's
net rate. Read [`00-overview.md`](00-overview.md) first for the investigation of what exists today and
where price is decided.

**Nothing is built yet.** This document is the agreed architecture; the phase plan is §9 and the
questions still open are §10 and §11.

## The design in one sentence

**A price is a ladder, not a lookup.** Each pricing level owns at most one strategy; the engine takes
one matching rule per level and **sums** their contributions onto the supplier's net rate, recording
each rung as its own immutable row so any price can be taken apart a year later.

> **First match wins *within* a level. Levels sum.**

## How to read this document

Every substantive statement is tagged, because the brief asked for these four to stay separate:

| Tag | Meaning |
| --- | --- |
| **[CONFIRMED]** | A requirement you specified. Not mine to change. |
| **[EXISTING]** | How the application already behaves, with the code that proves it. |
| **[RECOMMENDED]** | My proposal. Reversible — argue with it. |
| **[UNRESOLVED]** | A business decision nobody has made yet. Listed in §10. |

### Decisions locked

**[CONFIRMED]** Signed off and no longer open. The rest of this document is written as though they
were always settled.

| | Decision | Resolution |
| --- | --- | --- |
| **C1** | Which agency is the pricing root | An existing `agencies` row, named by the `pricing.main_office_agency_id` setting — **not** a query on `AgencyType::MainOffice`. **The resolver fails loudly when the setting is missing.** §11-C1 |
| **C2** | Does a Main Office user pay the Main Office markup | **No.** Main Office user → `[Main Office]`; Agency user → `[Main Office, Agency]`. The `chain()` dedupe stands. §3.1 |
| **C3** | What the wallet debits | **Model A** — `cost_amount`, not `total_amount`. The Agency's own markup is downstream margin, not owed to the platform. **No settlement or commission-payable system is to be built.** §7 |
| **D1** | Percentage basis | **`net` is the default.** The second level does **not** compound against the running total. `basis` stays a column so `running` is available per rule later. §3.2 |

---

## 0. Revision: Sub-Agent removed

### 0.1 Where Sub-Agent was referenced

**[EXISTING]** **Nowhere in the codebase.** A search across `app/`, `database/`, `config/`, `routes/`,
`resources/` and `tests/` returns nothing. It existed only in documentation:

| File | Mentions | Disposition |
| --- | --- | --- |
| `_docs/pricing/01-architecture.md` | 40 | **this file — rewritten** |
| `_docs/pricing/00-overview.md` | 5 | edited |
| `_docs/README.md` | 1 | edited |

There is no migration to unwind, no `AgencyType` case to drop, no data to clean. **The scope
reduction costs nothing** because it happened before implementation — which is the whole reason the
design was written before the code.

### 0.2 What is removed

- The `AgencyType::SubAgent` case. **`AgencyType` is untouched** — `main_office`, `outlet`, `itp`.
- The terminality invariant (*"a `sub_agent` may never be another agency's `parent_id`"*), and with
  it the guard that would have gone into `AgencyService::create()` and `update()`.
- Level 2 from the resolver, the layer table, the UI, the permissions and the phase plan.
- The third UI surface (the Sub-Agent pricing screen).
- **`AgencyType` gaining structural meaning.** The previous revision needed the type to constrain
  tree depth, which sat awkwardly beside the documented principle that *"an agency's type and its
  place in the parent tree never grant or withhold anything."* With Sub-Agent gone, **that principle
  is intact and untouched.**

### 0.3 What this simplifies

**The resolver no longer walks the tree.** This is the largest simplification, and it removes a
dependency on data hygiene that was fragile.

**[EXISTING]** `agencies.parent_id` is nullable, is documented as *"reporting and markups only… it
grants nothing"* ([`rbac/01-agencies.md`](../rbac/01-agencies.md)), and is very likely NULL on most
rows today. A design that walked `parent_id` to find the Main Office would silently skip it for
exactly those agencies — on a rule that must always apply.

**[RECOMMENDED]** With two levels the chain is a two-element list built directly:
`[Main Office, booker's Agency]`. **No walk, no cycle guard, no `parent_id` dependency, no ordering
question.** `parent_id` goes back to being purely descriptive, as documented.

Also simplified: the phase plan drops from seven phases to five; the settlement question (previously
the single biggest open item) becomes answerable from existing behaviour (§7); and refund treatment
largely resolves itself as a consequence (§10-D7).

---

## 1. Domain model

**[RECOMMENDED]** Namespace `App\Services\Pricing`. Nine pieces, each with one job.

| Type | Kind | Responsibility |
| --- | --- | --- |
| `Money` | value object | bcmath decimal string. **Money never touches a float** — `WalletService`'s existing rule (`:20`), and markup is the same money. |
| `PricingContext` | readonly DTO | Everything a rule may match on. §1.2. Built once, per priced item. |
| `PricingContextFactory` | service | `forFlight(FlightOffer)`, `forHotel(HotelOffer, RoomOffer)`. **The only place that knows a product's DTO shape** — this is what keeps product logic out of the engine. Reads `scope` straight off the offer, which Phase 1 already put there. |
| `StrategyResolver` | service | Booker → **ordered list of levels**. §3.1. |
| `RuleMatcher` | service | Strategy + context → the one matching rule, or null. First match by `priority`. |
| `Calculator` | interface | `compute(Money $basis, PricingRule $rule, PricingContext $ctx): Money`. One class per type. §1.3. |
| `CalculatorRegistry` | service | `calc_type` → `Calculator`. **Adding a calculator is registering a class, not editing the engine.** |
| `PricingLayer` | readonly DTO | One rung: level, agency, strategy, rule, basis, markup, running total. |
| `PriceBreakdown` | readonly DTO | Net + ordered `PricingLayer[]` + rounding delta + sell. The engine's only return type. Carries the visibility filter, §6. |
| `PricingEngine` | service | `quote(PricingContext, ?Agency $booker): PriceBreakdown`. **The single entry point.** |

### 1.1 The type separation that prevents double markup

**[CONFIRMED]** — your §11.

```php
final readonly class NetPrice  { public function __construct(public Money $amount) {} }
final readonly class SellPrice { public function __construct(public Money $amount) {} }
```

`PricingContext` accepts a `NetPrice` and nothing else. `PriceBreakdown` exposes a `SellPrice`. There
is no constructor, cast, or helper anywhere that converts a `SellPrice` back into a `NetPrice`.
Feeding a marked-up figure into the engine becomes **a type error at the call site**, not an inflated
fare in production. The remaining five defences are in §5.

### 1.2 `PricingContext`

**[CONFIRMED]** — your §10. Product logic stays out of the engine; the engine only reads fields.

```
product      flight | hotel                  net          NetPrice
supplier     tboair | tbohotel               currency     string
scope        domestic | international        paxCount, roomCount, nights
travelDate, bookingDate
attributes   map<string, scalar>   ← airline, cabin, isLcc, refundable, rating,
                                     city, country, mealType, origin, destination…
```

**[RECOMMENDED]** Product-specific fields live in one `attributes` map rather than as typed
properties. The matchable set differs per product and grows with each new one; typed properties would
mean the core DTO changes every time a product is added, which is the coupling your §10 asks to
avoid. The factory is the only code that knows which keys a product populates.

### 1.3 Calculators

**[CONFIRMED]** — your §9. Ship `fixed` and `percentage_markup`; register the rest later without
touching the engine.

| `calc_type` | Computation | Phase |
| --- | --- | --- |
| `fixed` | `value` | **3** |
| `percentage_markup` | `basis × value%` | **3** |
| `percentage_margin` | `basis × value% ÷ (100 − value)` | later |
| `per_pax` | `value × ctx.paxCount` | later |
| `per_room_night` | `value × rooms × nights` | later |
| `tiered` | band table on the rule | later |
| `none` | zero — an **explicit** pass-through for negotiated rates | later |

> **[UNRESOLVED — D2]** `percentage_markup` and `percentage_margin` are different numbers and the gap
> is not small. Markup is *of cost*; margin is *of sell*. 20% markup on ₱5,000 sells at ₱6,000; 20%
> *margin* sells at ₱6,250. Anyone who says "we work on 20%" means one of them, and which one is
> worth ₱250 a booking. Both will exist as types — the question is which the Main Office's own rules
> use. Ship `percentage_markup` first because your §8 example ("10% × ₱5,000 = ₱500") is unambiguously
> markup.

---

## 2. Database schema

**[RECOMMENDED]** Three new tables, four new columns on `bookings`, **zero changes to `agencies` or
`users`.**

### 2.1 `pricing_strategies`

| Column | Notes |
| --- | --- |
| `id` | |
| `agency_id` | FK → `agencies`, **NOT NULL, UNIQUE** |
| `name` | display only |
| `is_active` | a paused strategy contributes zero; not an error |
| `timestamps`, `softDeletes` | |

**[CONFIRMED]** A strategy belongs to an **Agency**, never a User. There is no `user_id` column —
see §5.1.

**[RECOMMENDED]** One strategy per agency, enforced by the unique index. Not many-with-date-ranges:
two strategies on one agency immediately raises *"which is live right now?"*, recomputed on every
quote. Seasonality belongs on the rule (`valid_from` / `valid_to`), where the matcher already has to
consider it.

**[RECOMMENDED]** `agency_id` is NOT NULL because **the Main Office is a real `agencies` row** —
`AgencyType::MainOffice` already exists, which is what your §4 asks for. This also avoids the
`NULL = platform default` pattern: MySQL permits many NULLs in a unique index, so "exactly one
default" would have needed a sentinel column. A real row also gets a name, a code, an audit trail and
a permission scope like every other agency. **See §11-C1** — nothing currently guarantees there is
only one row of that type.

### 2.2 `pricing_rules`

| Column | Notes |
| --- | --- |
| `strategy_id` | FK, `cascadeOnDelete` |
| `product` | `flight` \| `hotel` \| `*` — **string, not a DB enum** |
| `supplier` | nullable; `tboair` \| `tbohotel` \| null = any |
| `scope` | `domestic` \| `international` \| `any` — see [`00-overview.md`](00-overview.md) §1 |
| `matchers` | json — airline, cabin, `isLcc`, rating, city, refundable, meal plan, LOS… |
| `calc_type` | §1.3 |
| `value` | `decimal(12,4)` — four places, because a percentage needs them and money does not |
| `basis` | **`net` \| `running`** — required, no default. §10-D1 |
| `applies_to` | `total` \| `base_fare` \| `excl_ancillaries` — *which part* of the amount |
| `min_markup`, `max_markup` | nullable — floor and cap on **this rule's** contribution |
| `rounding` | `none` \| `1` \| `10` \| `50` \| `100`, plus direction |
| `priority` | int ascending — **first match wins within the strategy** |
| `valid_from`, `valid_to` | nullable — seasonality and promos |
| `is_active`, `version`, `timestamps` | `version` increments on edit; needed by §10-D8 |

Index `(strategy_id, is_active, product, priority)` — the matcher's access path.

**[RECOMMENDED]** `product` is a validated string rather than a DB enum so adding transfers or tours
later is a seeded row, not a migration on a table holding live pricing. `matchers` is JSON rather
than thirty nullable columns for the same reason `PricingContext.attributes` is a map — and it is
affordable because the index narrows to one strategy's active rules for one product first, leaving a
handful of rows to evaluate in PHP.

### 2.3 `booking_price_layers`

**[CONFIRMED]** — your §12. One immutable row per level.

| Column | Notes |
| --- | --- |
| `booking_id` | FK, `cascadeOnDelete` |
| `level` | int — `0` Main Office, `1` Agency |
| `agency_id` | FK — **whose margin this is** |
| `strategy_id`, `rule_id` | nullable FK, `nullOnDelete` — convenience pointers only |
| `rule_snapshot` | json — `calc_type`, `value`, `basis`, `matchers`, `version`; **copied, not joined** |
| `basis_amount`, `markup_amount`, `running_total` | `decimal(14,2)` |
| `created_at` | append-only, **no `updated_at`** |

UNIQUE `(booking_id, level)` — §5.6.

**[RECOMMENDED]** `rule_snapshot` is copied rather than joined for the same reason `hotel_bookings`
copies the hotel: *"a voucher presented at a front desk eighteen months later must read the same as
the day it was issued"* (`HotelBooking.php:9-15`). Rules are editable data; a booking must explain
itself after its rule has been changed twice or deleted.

Append-only mirrors `wallet_transactions` — *"never updated or deleted; a correction is a new
opposing entry"*. A repriced booking writes new layers; it never edits old ones.

> **`level` is an int, not a two-value enum.** That is the minimum honest representation of "which
> rung" — you need to order the rungs regardless, and with two levels the values are 0 and 1. It is
> **not** reserved schema for a future level; see §12 for what adding one would actually involve.

### 2.4 `bookings` — new columns

| Column | Meaning |
| --- | --- |
| `net_amount` | the supplier's rate. **This is what goes to TBO** ([`00-overview.md`](00-overview.md) §2) |
| `cost_amount` | net + Main Office markup — **what the wallet is debited**. §7 |
| `markup_total` | `total − net`, denormalised for list queries and margin reports |
| `total_amount` | the **sell** price. Keeps its current meaning as the headline figure |

**[RECOMMENDED]** Migration backfills `net_amount = cost_amount = total_amount` and
`markup_total = 0`. Every existing row stays correct and no displayed price moves.

`cost_amount` is derivable (`net + layer 0`), and is stored anyway for three reasons: the wallet debit
must reconcile against the ledger without a join; it is the column `TboHotelBookPayload` must *not*
read; and it has a level-count-agnostic definition — *the running total at the level above the
booker* — so §12 changes nothing about the wallet.

**[CONFIRMED]** `ancillary_total` is unchanged — a component of net, not a third total.

### 2.5 Relationships

```
agencies ──1:1── pricing_strategies ──1:*── pricing_rules
    │
    ├──1:*── users                    bookings ──1:*── booking_price_layers
    │                                                        │
    └──────────────────(agency_id: whose margin)─────────────┘
```

Exactly the shape in your §14. No `sub_agent`, no parent relationships, no user pricing table, no
user markup columns, no third-level table.

---

## 3. Pricing resolution algorithm

**[CONFIRMED]** — your §7. Summation, never an override chain.

### 3.1 The chain

```php
StrategyResolver::chain(?Agency $booker): array   // [[level, Agency], …] root-first
{
    // From the pricing.main_office_agency_id setting. THROWS when unset or dangling —
    // never falls back to a type query, never guesses. §11-C1
    $mainOffice = $this->mainOffice();
    $chain = [[0, $mainOffice]];

    // A Main Office user must not be charged the Main Office's markup twice. §11-C2
    if ($booker !== null && $booker->isNot($mainOffice)) {
        $chain[] = [1, $booker];
    }

    return $chain;
}
```

**[EXISTING]** `users.agency_id = NULL` means platform staff (`User::isPlatformStaff()`), who resolve
to `[Main Office]` alone and are **not charged** — `ChargesWallet` already no-ops for a user with no
agency (`:30`).

### 3.2 The engine

```
quote(PricingContext $ctx, ?Agency $booker): PriceBreakdown

  1  net     = ctx.net
  2  running = net
  3  layers  = []

  4  foreach (level, agency) in StrategyResolver.chain(booker):
       strategy = agency.pricingStrategy
       if strategy is null or !strategy.is_active: continue       // contributes zero
       rule = RuleMatcher.firstMatch(strategy, ctx)                // by priority; null → zero
       if rule is null: continue

       basis  = rule.basis === 'net' ? net : running               // CONFIRMED D1 — default 'net'
       basis  = rule.applies_to.slice(basis, ctx)
       markup = CalculatorRegistry.for(rule.calc_type).compute(basis, rule, ctx)
       markup = clamp(markup, rule.min_markup, rule.max_markup)

       running  = running.plus(markup)
       layers[] = PricingLayer(level, agency, strategy, rule, basis, markup, running)

  5  running = clampTotal(running, net, platform.max_total_markup)  // §10-D5
  6  sell    = round(running, platform.rounding)                    // ONCE, at the end — §10-D3
  7  return PriceBreakdown(net, layers, sell, delta: sell - running)
```

Worked against your example — supplier ₱5,000, both levels `fixed`:

| Level | Agency | Rule | Basis | Markup | Running |
| --- | --- | --- | --- | --- | --- |
| 0 | Main Office | fixed ₱500 | 5,000.00 | +500.00 | 5,500.00 |
| 1 | Agency ABC | fixed ₱200 | 5,000.00 | +200.00 | **5,700.00** |

And your §8 percentage example — both levels 10%, `basis: net`:

| Level | Rule | Basis | Markup | Running |
| --- | --- | --- | --- | --- |
| 0 | 10% | 5,000.00 | +500.00 | 5,500.00 |
| 1 | 10% | **5,000.00** | +500.00 | **6,000.00** |

On `basis: running` the second rung would compute 10% of 5,500 = ₱550 and total ₱6,050. **Both are
legitimate policies; neither should ever be an accident**, which is why `basis` is a required column
with no default. Your §8 confirms `net` as the recommended default.

### 3.3 Three properties worth stating

**[RECOMMENDED]**

- **Order-independent when every rule uses `basis: net`.** Addition commutes, so the total does not
  depend on the walk direction. A large part of why `net` is the default.
- **A missing level is zero, never a failure** — **[CONFIRMED]**, your §5. No strategy, inactive
  strategy, or no matching rule all contribute nothing and the ladder continues. Today's behaviour
  (everyone pays net) is exactly what an empty configuration produces, so **the engine can ship live
  before a single rule exists.**
- **The engine reads no booking, wallet or session.** It is a pure function of context and
  configuration — which is what makes the §8 preview *exact* rather than an estimate.

**[RECOMMENDED]** Strategies and their active rules are cached per agency with explicit invalidation
on write, following `Settings` (`Cache::rememberForever` + `forget`) and `RbacCache`. Two agencies per
quote, so a cold path is two queries.

---

## 4. Booking price layers

**[CONFIRMED]** — your §12. `booking_price_layers`, schema in §2.3.

For the worked example the booking stores:

| level | agency | rule_snapshot | basis | markup | running |
| --- | --- | --- | --- | --- | --- |
| 0 | Main Office | `{calc_type: fixed, value: 500.00, basis: net, version: 3}` | 5,000.00 | 500.00 | 5,500.00 |
| 1 | Agency ABC | `{calc_type: fixed, value: 200.00, basis: net, version: 1}` | 5,000.00 | 200.00 | 5,700.00 |

Which answers, exactly as your §12 requires:

| Question | Answer |
| --- | --- |
| Why ₱5,700? | two rows, in order, with the arithmetic |
| Main Office contribution? | `markup_amount WHERE level = 0` |
| Agency contribution? | `markup_amount WHERE level = 1` |
| Which rule? | `rule_id`, plus `rule_snapshot` if the rule has since been deleted |
| Its values at the time? | `rule_snapshot` — copied, so later edits cannot rewrite history |

**[RECOMMENDED]** One row per rung rather than one JSON blob on the booking, because the blob is fine
for forensics and useless for the report both levels actually want: *"how much margin did Agency X
earn last month?"* is `SUM(markup_amount) WHERE agency_id = X AND level = 1`. This table is
simultaneously the audit trail and the margin ledger.

---

## 5. Preventing double markup

**[CONFIRMED]** — your §11. Six defences.

1. **Type separation** — §1.1. There is no path from `SellPrice` back to `NetPrice`.
2. **The engine runs at exactly one place per product**: the serialization boundary, on the way out
   of the controller. Never in a DTO constructor, never in Blade, never in a job.
3. **Caches hold net only.** **[EXISTING]** `FlightSearchCache` and `HotelSearchCache` store the
   supplier response keyed per user and environment; the engine runs *after* the cache read. Second
   benefit: a rule edited at 10:00 takes effect immediately rather than after the TTL.
4. **Booking re-prices from the supplier.** **[EXISTING]** `BookingService` re-quotes through
   FareQuote (`:52`) and `HotelBookingService` through PreBook (`:68`) — both return **net** — and
   `HotelBookingService` already documents the principle: *"the price the browser sends back is what
   the agent was shown, which is evidence about the gate and never a source of truth about what to
   charge"* (`:48-51`). The engine re-runs on the freshly fetched net.
5. **`basis` is explicit and required** — §3.2. `net` vs `running` is never inferred.
6. **One layer per level, enforced on write** — UNIQUE `(booking_id, level)`. If a bug ever ran the
   engine twice, the second insert fails loudly instead of quietly doubling the margin.

---

## 6. Price visibility

**[CONFIRMED]** — your §13. A security boundary, not a UI preference.

**[RECOMMENDED]** `PriceBreakdown::forViewer(User): array` collapses every layer **above** the
viewer's own level into a single opaque `cost` figure and drops the rest **before serialization**.

| Viewer | Sees |
| --- | --- |
| Main Office / platform staff | `net`, every layer, `sell` |
| Agency user | `cost` (= net + Main Office markup, **one opaque number**), own `markup`, `sell` |

An Agency never receives `net_amount` or the Main Office's markup as a separate value — not in the
page, and **not in the JSON**. Both product search pages fetch results as JSON
(`FlightController::search`, `HotelController::search`), so a Blade-level filter is one devtools panel
from useless.

> **[RECOMMENDED]** Get this into the DTO in the same commit as the engine. Retrofitting it means
> auditing every response that ever carried a price — search, rooms, fare quote, booking show,
> e-ticket, voucher.

---

## 7. Wallet — what the existing system already establishes

**[CONFIRMED]** — your §17 asks me to identify the existing behaviour rather than pick a model. Here
it is, and it does answer the question.

### 7.1 What the wallet is today

**[EXISTING]**

- *"A prepaid balance per agency, topped up through a reviewed request cycle"*
  ([`wallet/00-overview.md`](../wallet/00-overview.md)). An agency deposits real money with the
  platform — `wallet_load_requests.payment_reference` is *"the requester's bank/e-money reference"* —
  and the platform approves the credit.
- `ChargesWallet::chargeWallet()` debits the booker's agency for `total_amount`, which **today is
  exactly the supplier fare**, since no markup exists.
- `WalletService::debit()` refuses to overdraw; the balance is drawn down as bookings are made.
- Refunds read **the original ledger entry**, not the booking (`TransitionsBooking:74`), so whatever
  was debited is exactly what comes back.
- Platform staff are never charged — no agency, no wallet (`ChargesWallet:30`).

### 7.2 What that establishes

**[EXISTING, by derivation]** The balance is money the Agency has deposited *with the platform*, and a
debit is **the platform collecting what the Agency owes the platform.**

The Agency owes the platform the supplier net plus the platform's own markup. It does **not** owe the
platform its own markup — that is revenue the Agency earns from its own customer, in a transaction
the platform is not party to. Charging it would mean an Agency pays its own margin to the platform
and then has to claim it back.

**Therefore the existing system establishes model A: charge the Agency's cost.**

```
wallet debit = cost_amount = net + Main Office markup       (₱5,500)
displayed / printed / reported = total_amount = sell        (₱5,700)
the Agency's ₱200 is collected from its own customer, off this platform
```

**Model B would require what does not exist**: a commission-payable ledger, payout runs, and
reconciliation. `wallet.adjust` is not a substitute — it is documented as *"effectively the right to
mint money… keep it on office/platform roles"*, precisely the wrong tool for routine settlement.

**[CONFIRMED]** Your §17 says not to build a settlement engine unless explicitly required. Nothing
here requires one, and §2.4's column split leaves B available later without a rewrite.

**[RECOMMENDED]** One consequence worth naming: `cost_amount` and `total_amount` diverge, so the
bookings list will show ₱5,700 while the wallet ledger shows ₱5,500. That is **informative rather
than confusing** — the difference is the agency's own margin, which §6 says they may see. The
bookings screen should label both rather than showing one and letting someone reconcile by hand.

> **[CONFIRMED — C3]** Model A is signed off. The wallet debits `cost_amount`. On the worked example:
> `net_amount` ₱5,000 · `cost_amount` ₱5,500 · `markup_total` ₱700 · `total_amount` ₱5,700, with
> **₱5,500 leaving the wallet**. No settlement or commission-payable system is to be built.

---

## 8. UI structure

**[CONFIRMED]** — your §15 and §16. Two surfaces; the preview uses the real engine.

### 8.1 Main Office — `markup.office.view` / `markup.office.edit`

**The ladder preview.** Pick an agency and a product, type a net amount:

```
Supplier net                        5,000.00
  + Main Office   fixed ₱500          500.00   →   5,500.00
  + Agency ABC    fixed ₱200          200.00   →   5,700.00
                                    ─────────
  Selling price                     5,700.00
  Total markup                        700.00   (14.00%)
```

**[RECOMMENDED]** Build this first — before the engine is wired into search. It is the feature made
legible in one view, it is how someone verifies a change before saving it, and because the engine is
a pure function (§3.3) it is **exact**: the same code path that prices a real booking, which is what
your §15 requires. It doubles as the acceptance test for every calculator.

**The strategy & rule editor**, and a **hierarchy view** listing every agency with its effective
markup for a chosen product — flagging agencies with no strategy (they contribute zero, worth seeing
at a glance rather than discovering in a margin report).

### 8.2 Agency — `markup.view` / `markup.edit`

**[RECOMMENDED]** Under **My Agency**, where an agency's own administration already lives
(`fdf9b05 refactor(nav): let My Agency own the Users and Roles links`, and the wallet before it).

```
Your cost                           5,500.00      ← one opaque figure, §6
Your markup     fixed ₱200            200.00
                                    ─────────
Selling price                       5,700.00
```

Plus their own rule editor, scoped by policy to their strategy. **[CONFIRMED]** An Agency cannot
modify the Main Office strategy — enforced by permission and policy (§5 of the list below), not by
hiding a button.

**[RECOMMENDED]** One Blade component parameterised by scope serves both audiences, following
`admin/roles/_permission-grid.blade.php`, which already shares a view across agency and platform
contexts. Two implementations would drift.

### 8.3 Ensuring Users cannot configure pricing

**[CONFIRMED]** — your §3. Four layers, weakest to strongest:

1. **Permission** — `markup.*` simply not on ordinary users' roles. **[EXISTING]** There is no
   `Gate::before`, so nobody — not even an admin — bypasses a missing permission.
2. **Policy** — `PricingStrategyPolicy` requires the actor's `agency_id` to equal the strategy's, so
   `markup.edit` in one agency does nothing in another.
3. **Route and nav gating**, as every other module does.
4. **The schema cannot express it** — `pricing_strategies` has **no `user_id` column**. A user-owned
   strategy is not a storable thing, so no bug and no mis-assigned role can create one. Your "there
   must be no user-owned pricing strategy" is a structural impossibility rather than a rule someone
   must remember.

**[EXISTING]** `config/rbac.php:257-272` already carries `markup` (view, edit) and `markup.office`
(view, edit) as `route => null` stubs under a "Markups" group. Both need a route and their `enabled`
flag; the permission rows already sync.

---

## 9. Phase plan

**[RECOMMENDED]** Five phases, one commit each, independently shippable and tested.
**Phases 1–3 change no price.**

| Phase | Contents | Price moves? |
| --- | --- | --- |
| **1** ✅ | `TravelScope` classification, display only — [`00-overview.md`](00-overview.md) §1.3. **Done**: one enum + `App\Support\TravelScopeResolver` replacing all three `isDomestic()` implementations, `country_code` on the curated airport list, `scope` on `FlightOffer`/`HotelOffer`/`FareQuote`, chips on both results pages | No |
| **2** ✅ | Schema: `net`/`cost`/`markup_total`/`total` on bookings, `booking_price_layers`, backfill. `TboHotelBookPayload` → `net_amount`. Hotel price-change gate compares like with like | No |
| **3** ✅ | **The engine, complete and tested, wired to nothing.** `pricing_strategies` + `pricing_rules`; `PricingEngine`, `StrategyResolver`, `RuleMatcher`, `CalculatorRegistry`; the `fixed` and `percentage_markup` calculators; the Main Office strategy row (**empty**) + `pricing.main_office_agency_id`; the Main Office pricing UI and ladder preview, `markup.office.*` gated | No — **ships empty** |
| **4** ✅ | Wire the already-tested engine into flight and hotel search and both booking paths. `PriceBreakdown::forViewer` (§6). `ChargesWallet` → `cost_amount`. **This is the commit that activates pricing** | **Yes** |
| **5** ✅ | Agency strategies behind `markup.*` and `PricingStrategyPolicy`, as a **Markup tab on the agency hub** rather than a separate page — an agency reaches its pricing where it already reaches its wallet, users and roles. Margin reporting off `booking_price_layers` | Yes |

**All five phases are built.** What Phase 4 turned on is still inert in practice until a
pricing root is named and its first rule is written — both operations, not deploys.

**[RECOMMENDED]** Phase 3 ships the whole engine with an **empty** Main Office strategy: it runs,
contributes zero, and every price stays as it is. The first rule then becomes an operation rather
than a deploy — which is the point of putting rules in the database — and Phase 4 flips markup on
with the machinery already proven in production.

Phase 2 is worth doing even if pricing were cancelled tomorrow: it fixes the `TboHotelBookPayload`
defect described in [`00-overview.md`](00-overview.md) §2, which is a live breakage waiting for the
first non-net `total_amount`.

---

## 10. Unresolved business decisions

**[UNRESOLVED]** — all of them. Your §18's list, carried forward. None is invented; each is a place
the specification and the existing application are both silent.

> **None of these blocks the code, and that is deliberate.** Every one is expressible as a column on
> `pricing_rules` or a key in `config/pricing.php`, and every default is the conservative reading:
> `basis` defaults to `net`, `rounding` to none, `max_total_markup` to unset, discounts refused,
> ancillaries inside the basis. **Answering them is configuration, not development.**

| # | Decision | Why it matters | Needed by |
| --- | --- | --- | --- |
| ~~**D1**~~ | ~~Percentage basis~~ | **RESOLVED: `net` is the default.** Two 10% rules on ₱5,000 → **₱6,000**. No compounding against the running total by default; `basis` stays a column so `running` is available per rule later | — |
| **D2** | **`percentage_markup` vs `percentage_margin`** as the house convention | ₱250 per booking on a ₱5,000 fare at "20%" (§1.3) | Phase 3 |
| **D3** | **Rounding: per layer, or once at the end** | Rounding each rung then summing makes the breakdown visibly fail to add up — worse than the drift. **Recommendation: full precision, round once (§3.2 step 6), store the delta**; per-rule `rounding` then means "round this contribution", used sparingly | Phase 3 |
| **D4** | **Are negative markups / discounts allowed** | A discount has different authorization, audit and accounting from a markup. **Recommendation: no in v1** — not a rule with a negative `value`; smuggling it in guarantees a later untangling | Phase 3 |
| **D5** | **Maximum total markup** | Two individually reasonable levels can stack to something the end customer sees as absurd. **Recommendation: a platform ceiling applied at §3.2 step 5**, with the hierarchy view flagging chains that hit it. Decide whether hitting it truncates or refuses the configuration | Phase 3 |
| **D6** | **Are ancillaries in the markup basis** | Flight SSR baggage/meals flow through `applyAncillaries()` into the charged total. `applies_to` is the switch; the default is a business call | Phase 3 |
| **D7** | **Refund treatment of markup** | **[EXISTING]** *"Cancellation is refunded in full… there is no airline-penalty or service-fee model"*. Under model A the Agency was never charged its own markup, so **only the Main Office's markup is in question** — refunding `cost_amount` returns it. Model A makes this much smaller than it looked | Phase 4 |
| **D8** | **Rules changing between search and booking** | Re-pricing at booking (§5.4) now re-runs *our* rules too, so a total can move for a reason the supplier had nothing to do with. **Recommendation: pin resolved rule IDs + `version` into the quote, re-resolve at booking, and if they changed surface it exactly like the hotel price-change gate already does** (`HotelBookingService:73`) — *"confirm the new price to continue"* — never a silent recharge | Phase 4 |
| **D9** | **VAT treatment of markup** | Markup is service revenue and in PH very likely VATable; the supplier's net is not our sale. **[EXISTING]** the live system hardcodes `IsVATApplicable => true`. Is ₱500 VAT-inclusive or exclusive? Answer before rules are configured — reinterpreting live rules later reprices history | Phase 3 |
| **D10** | **Zero / near-zero net amounts** | A ₱0 infant fare takes 10% of nothing. `min_markup` provides a floor — and is also how a free component acquires ₱1,500 of markup. Decide per product | Phase 3 |
| **D11** | **Visibility** — is the model in §6 the intended boundary | Confirms an Agency may see its own markup but never net or the Main Office's markup separately | Phase 4 |
| **D12** ⚠️ | **A percentage-on-net agency rule lets that agency derive the supplier net** | Found while building Phase 3, by the test that asserts the net never reaches an agency payload. If an Agency sets *"10% of net"* and is shown its own markup of ₱500 — which is theirs, and which §6 says they may see — then net = ₱5,000 falls straight out. The leak is arithmetic, not a bug, so no amount of redaction closes it. **Phase 5 ships the recommendation: an agency percentage rule is forced onto `basis: running`** — a percentage of *their cost*, which they already know, and which is also the more natural reading of "I add 10% to what I pay". Fixed rules are untouched; a flat ₱200 says nothing about what the room cost. **Confirm or reverse** — reversing is one validation rule in `StoreAgencyPricingRuleRequest` | **shipped, needs sign-off** |

---

## 11. Contradictions — all resolved

**[CONFIRMED]** Three were found in the existing application. All three are now signed off; the
recommendations below were accepted as written.

### C1 — Nothing guarantees exactly one Main Office ✅

**[EXISTING]** `AgencyType::MainOffice` exists, but nothing prevents two agencies carrying it, and
`parent_id` is nullable so "the root" is not reliably identifiable either. `Agency::scopeActive` and
`AgencyService` impose no singularity. A resolver that queried `type = main_office` could get two
rows and would have to pick one arbitrarily — on the rule that always applies.

**[RECOMMENDED]** A `settings` row — `pricing.main_office_agency_id` — read through the existing
`Settings` service (cached, explicitly invalidated). It makes "which agency is the Main Office for
pricing" **explicit, auditable and singular by construction**, without adding a constraint to a live
table or inferring intent from a type that is documented as carrying none. Set in Phase 3; the
resolver fails loudly if it is unset rather than guessing.

**[CONFIRMED]** The Main Office **is** an existing `agencies` row.
`pricing.main_office_agency_id` is the authoritative pricing root and points at it;
`AgencyType::MainOffice` is **not** queried for this purpose. **The resolver throws when the setting
is missing or names a row that does not exist — it never guesses and never falls back to a type
query.**

### C2 — A Main Office user would be charged the Main Office markup ✅

If the Main Office is an agency row, its own staff have `agency_id` = that row. A naive chain gives
`[Main Office, Main Office]` — **the level-0 rule applied twice**, a genuine double-markup on exactly
the people configuring it, and the UNIQUE `(booking_id, level)` guard would not catch it because the
two rows have different levels.

**[RECOMMENDED]** The dedupe in §3.1: a booker whose agency *is* the Main Office contributes no level
1. Their price is net + Main Office markup, which is also their cost — consistent, and a Main Office
user is selling at the house price by definition.

**[CONFIRMED]** The Main Office **does** book directly, and must **not** pay its own markup. The
chains are exactly:

```
Main Office user   [Main Office]              → net + Main Office markup = selling price
Agency user        [Main Office, Agency]      → net + Main Office markup + Agency markup = selling price
```

For a Main Office user, cost and selling price are the same figure — they sell at the house price by
definition.

### C3 — Extending the wallet rule from one level to two ✅

**[EXISTING]** §7 derives model A from what the wallet already is, and the derivation is sound — but
the behaviour it extends was established when there was one level and cost equalled net. The existing
code cannot, strictly, testify about a case that has never existed.

**[CONFIRMED]** Model A, as derived. The wallet is debited **`cost_amount` (₱5,500)**, not
`total_amount` (₱5,700). The Agency's ₱200 is its own downstream margin and is not owed to the
platform. **No settlement or commission-payable system is to be built as part of this feature.**

This was the one place where existing behaviour was being *extended* rather than read, which is why
it needed sign-off; it now has it. `ChargesWallet` still changes in **Phase 4**, not before.

---

## 12. Extensibility — adding a third level later

**[CONFIRMED]** Your §19: keep the engine generic, model nothing.

**Nothing in this design is reserved for a future level, and nothing would need rewriting to add
one.** The genericity lives in two places that are already the simplest correct implementation for
two levels:

1. **`StrategyResolver::chain()` returns an ordered list.** With two levels it returns two entries.
   A third level means this method returns three. It is the **only** method that would change.
2. **`PricingEngine` loops that list.** It has no knowledge of how many entries there are, what they
   are called, or how they relate. Adding a level does not touch the engine, the matcher, the
   calculators, `PriceBreakdown`, or `booking_price_layers` — whose `level` int and `agency_id`
   already say "which rung, whose margin" without caring how many rungs exist.

What a third level *would* additionally require, and what this phase deliberately does **not**
build: a way to identify it in the org tree, its own permission scope, its UI surface, and a decision
about which rung the wallet debits (§7). Those are organizational and commercial questions, not
pricing-engine ones — which is precisely why removing them now costs nothing later.
