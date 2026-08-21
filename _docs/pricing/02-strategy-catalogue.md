# Pricing — the strategy catalogue

**What a pricing rule could be, beyond a flat amount and a percentage of net.** Read
[`01-architecture.md`](01-architecture.md) first: it is the built model, and this document changes
none of it. Everything here sits on top of the engine as written — the ladder still sums, every level
still works from the supplier net, and the extension points are the ones §1.3 of that document
described and left open.

**Nothing in this catalogue moves a live price.** Every item is inert until a rule is written to use
it, which is what makes each one safe to ship on its own. That is the same property that let the
engine itself go live without repricing anything.

## How to read this document

The tags are [`01`](01-architecture.md)'s, carried forward:

| Tag | Meaning |
| --- | --- |
| **[EXISTING]** | How the application already behaves, with the code that proves it. |
| **[RECOMMENDED]** | A proposal. Reversible — argue with it. |
| **[UNRESOLVED]** | A business decision nobody has made yet. Listed in §8. |

And one convention of its own — the **cost** of each item, which is the whole reason to catalogue
them rather than argue them:

| Cost | What it means |
| --- | --- |
| **form** | A field the schema already carries and the validator already accepts. No PHP. |
| **class** | A `Calculator` implementation and a line in `CalculatorRegistry`. The engine, the matcher, the resolver and the layers table are untouched. |
| **context** | A key added to `PricingContext::$attributes` by `PricingContextFactory`. No engine change, no migration. |
| **column** | The above, plus a migration on `pricing_rules`. |
| **subsystem** | Reaches the booking spine, the wallet, RBAC or the audit log. Its own phase. |

---

## 0. Where the engine stands

### 0.1 The two extension points

**[EXISTING]** `CalculatorRegistry.php:26-27` registers exactly two calculators. `CalcType.php:18-24`
declares **seven** cases; `CalcType::implemented()` (`:57`) returns two of them, and `options()`
(`:65`) derives the form's select from `implemented()` — so a type without a class behind it cannot be
chosen, and a rule that cannot be computed is refused by the form that would have created it rather
than discovered mid-quote.

> **Adding a calculation type is registering a class.** `PricingEngine` never branches on
> `calc_type`; it asks the registry and calls what it gets back (`PricingEngine.php:148`). That is the
> single most valuable property of the current design and every item in §2 relies on it.

The second extension point is quieter and cheaper. `PricingRule::matchesAttributes()`
(`PricingRule.php:138`) walks the `matchers` JSON against `PricingContext::attribute()` — a scalar is
equality, a list is "any of". **Any key the factory puts on the context becomes matchable with no
further code at all.** §3 is entirely built on this.

### 0.2 What the forms actually expose

**[EXISTING]** The gap between what the schema accepts and what anyone can reach is larger than it
looks, and it differs between the two forms:

| Field | Office form | Agency form | Validated in `StorePricingRuleRequest` |
| --- | --- | --- | --- |
| `product`, `scope`, `calc_type`, `value`, `description` | yes | yes | yes |
| `supplier` | yes | hidden, `""` | yes |
| `min_markup` / `max_markup` | yes — *Floor* and *Cap* | **no** | yes |
| `applies_to` | hidden, `total` | hidden, `total` | yes |
| `rounding` | hidden, `none` | hidden, `none` | yes |
| `matchers` | **no** | **no** | yes |
| `valid_from` / `valid_to` | **no** | **no** | yes |
| `basis` | forced `net` | forced `net` | forced in `prepareForValidation()` `:42` |
| `priority` | not asked | not asked | defaulted to `100`, `:42` |

Office form: `resources/views/admin/pricing/index.blade.php:160-186`, hidden inputs at `:204-207`.
Agency form: `resources/views/admin/agencies/_markup.blade.php:225-247`, hidden inputs at `:263-267`.

`basis` and `priority` are deliberate — C5 removed both as user-facing controls, and forcing them
server-side rather than trusting a hidden field is the correct implementation of that decision. The
rest are simply unbuilt.

---

## 1. Supported today, not yet reachable

**[EXISTING]** Six strategies need no code. Each reads like a missing feature and is really a column
nobody has surfaced. **Cost: form.**

| Strategy | How it is already expressed | Example |
| --- | --- | --- |
| **Percentage with a floor** | `min_markup` clamps the markup upward — the "greater of" case (`PricingEngine.php:150`) | 8%, at least ₱500 |
| **Percentage with a cap** | `max_markup` clamps it downward — the "lesser of" case | 10%, at most ₱3,000 |
| **Percentage inside a corridor** | Both together | 10%, ₱500–₱3,000 |
| **Percentage plus a service fee** | Two rules in one strategy. Matching is cumulative (C4), so both fire and the contributions sum | 10% + ₱350 |
| **Seasonal / campaign windows** | `valid_from` / `valid_to`, measured against the **travel** date when the context has one, falling back to the booking date (`PricingRule.php:116-129`) | 15 Dec – 5 Jan, +5% |
| **Targeted by attribute** | The `matchers` JSON. Flights carry `airline`, `cabin`, `isLcc`, `isRefundable`, `stops`, `origin`, `destination`; hotels carry `hotelCode`, `countryCode`, `cityCode`, `rating`, `isRefundable`, `mealType`, `withTransfers` | `{"rating": [4, 5]}` |
| **Markup on fare, not tax** | `applies_to` = `base_fare` or `excl_ancillaries` — the industry norm on long-haul, where tax can approach half the ticket (D2 in [`00`](00-overview.md) §7) | 12% of base fare |

**[RECOMMENDED]** Surface `matchers`, `valid_from`/`valid_to` and `applies_to` in both forms, and
`min_markup`/`max_markup` in the **agency** form, before writing a single new calculator. It is the
cheapest flexibility available and it is the flexibility most often actually asked for — a seasonal
rule and an airline-specific rule cover a large share of real requests.

One caution on `applies_to`: it is honoured only when `basis` is `net` (`PricingEngine.php:130-143`),
and on hotels there is no fare/tax split on the context, so `base_fare` silently falls back to the
full net (`PricingContext.php:68-78`). Say so in the form rather than letting an office user believe a
hotel rule is excluding something.

---

## 2. Calculator types

### 2.1 The five already declared

**[EXISTING]** These are enum cases with no class behind them. Each is one implementation of
`Calculator::compute()` plus a registry line plus an entry in `implemented()`. **Cost: class**, except
where noted.

| Type | Arithmetic | Where it earns its place |
| --- | --- | --- |
| **`percentage_margin`** | `net × v ÷ (100 − v)` | "We work on 20%" means margin to a finance team and markup to an ops team. On ₱5,000 that is ₱1,250 against ₱1,000 — ₱250 a booking. `PercentageMarkupCalculator`'s own docblock already spells the distinction out; this is the other half of it. **D2 in [`01`](01-architecture.md) §10 is the house convention, and it is still open — but both types should exist regardless of which one the form defaults to.** |
| **`per_pax`** | `v × paxCount` | The air-trade norm: a transaction fee per ticket issued. `paxCount` is on the context (`PricingContext.php:33`) and no calculator reads it. |
| **`per_room_night`** | `v × roomCount × nights` | The correct axis for hotels. `roomCount` and `nights` are on the context (`:34-35`) and likewise unread. |
| **`none`** | `0` | An **explicit** zero for negotiated corporate and staff rates, so nobody wonders whether a rule was missing. Also the honest way to say "this supplier is pass-through". |
| **`tiered`** | band lookup on the basis | 12% under ₱10k, 8% to ₱50k, 5% above. Protects the margin on cheap fares without making long-haul uncompetitive, and it is the single most requested shape in travel retail. **Cost: class + column** — see §2.3. |

### 2.2 Three worth adding

**[RECOMMENDED]**

| Type | Arithmetic | Rationale | Cost |
| --- | --- | --- | --- |
| **`per_segment`** | `v × segments` | A multi-city itinerary is more work than a one-way, and per-pax does not capture it. The flight branch of the factory does not emit a segment count today. | class + context |
| **`price_point`** | markup sized so sell lands on a chosen ending | ₱7,999 rather than ₱7,847.63. Broader than `pricing.rounding`, which only rounds **up to a step** and only once, globally, at the end (`PricingEngine.php:106-111`). | class + column |
| **`fee_gross_up`** | `basis × f ÷ (100 − f)` | Absorbs a payment-gateway or VAT percentage so the amount **retained** is the number that was intended. Arithmetically identical to `percentage_margin`; kept separate so the margin report can distinguish *our margin* from *cost recovery*, which is exactly what D9 (VAT) will need. | class |

### 2.3 One migration, once: a `params` column

**[RECOMMENDED]** `value` is a single `decimal(12,4)`
(`2026_08_14_000003_create_pricing_tables.php:52`). Any type carrying more than one number — tier
bands, a price-point ending, a rate table — needs somewhere to put them.

Add `params` as a nullable JSON column on `pricing_rules`, **once**, rather than a column per type.
Two requirements come with it:

1. **It must travel into `PricingRule::snapshot()`** (`PricingRule.php:175`). A booking priced on the
   ₱10k/₱50k bands cannot explain itself a year later if the bands moved and only `value` was copied.
2. **Do not reuse `matchers` for it.** That column answers *does this rule apply*; `params` answers
   *what does it compute*. Fusing them makes `matchesAttributes()` walk keys that are not matchers.

---

## 3. Strategies that need no calculator at all

**[RECOMMENDED]** These are not new arithmetic. They are new things to **match on** — one more key in
the `attributes` array the factory builds (`PricingContextFactory.php:38-45` for flights, `:113-120`
for hotels), matched by machinery that already exists. No engine change, no schema change.
**Cost: context.**

| Strategy | Key to add | What it buys |
| --- | --- | --- |
| **Advance purchase / lead time** | `daysToDeparture` | Take more on a last-minute booking, less on one made six months out. Derived from `travelDate` and `bookedOn()`, both already on the context — three lines in the factory. Pairs naturally with `tiered`. |
| **Partner grade** | `agencyTier` | **The lever for pricing ITPs differently from one another.** One Main Office rule matching `{"agencyTier": "gold"}` charges a high-volume partner less, from a single strategy, with no per-agency rule sprawl. Needs a `tier` column on `agencies` and the booker passed into the factory. |
| **Partner type** | `agencyType` | The same idea at coarser grain, and free: `AgencyType` already separates Main Office, Outlet and ITP. One office rule for outlets, another for ITPs. |
| **Length of stay** | `nights` | Already on the context as a typed property, and therefore **not** matchable — `attribute()` reads only the array. Copying it in makes long-stay rules possible. |
| **Day of week** | `travelDow` | Finer than `valid_from`/`valid_to`, which can only express a contiguous range. |
| **Route or market band** | — | Already matchable on flights: `{"destination": ["NRT", "ICN", "SIN"]}`. |

> **The booker is not on the context today.** `PricingContext` describes *what is being priced*, not
> *who is buying* — the agency arrives separately, as the engine's `$booker` argument. `agencyTier`
> and `agencyType` are the first matchers that cross that line. Passing the booker into the factory is
> the honest way to do it; reading it from the session inside the factory is not, because it would
> break the purity `PricingEngine`'s docblock and the ladder preview both depend on.

---

## 4. How the Main Office and an ITP divide one fare

Everything above changes what **one rule** computes. This section changes how the **levels relate**,
and it is the part that is a business decision rather than an engineering one.

### 4.1 The ladder today

**[EXISTING]** Both levels add, independently, off the same supplier net (C4, D1, D12):

| | | |
| --- | --- | --- |
| Supplier net | | ₱5,000.00 |
| Level 0 — Main Office | 10% of net | + ₱500.00 |
| Level 1 — ITP | 10% of net, **not** of the running total | + ₱500.00 |
| **Customer pays** | ITP's cost is ₱5,500, opaque | **₱6,000.00** |

The consequence worth naming: **two ITPs quote the same fare at different prices**, and nothing stops
one pricing itself out of the market or undercutting the house.

### 4.2 The six models

**[RECOMMENDED]** Roughly in order of what they cost. They are not exclusive — a `mode` column and a
corridor can ship together, and both are useful before any commission model is attempted.

| # | Model | Who sets the customer price | What the ITP controls | Cost |
| --- | --- | --- | --- | --- |
| **M1** | **Additive** — today | Both, independently | Its own markup, without limit | — |
| **M2** | **Corridor** | ITP, inside office bounds | Its markup within a published floor and ceiling | column + subsystem-lite |
| **M3** | **Commission** | Main Office only | Nothing at quote time; it negotiates a rate | subsystem |
| **M4** | **Exclusive rules** | Whichever level owns the agreement | Per-account pricing without disabling its other rules | column |
| **M5** | **Agent discretion** | The agent, per booking | Each quote individually | subsystem |
| **M6** | **Volume rebate** | Unchanged | Its volume | reporting only |

**M2 — Corridor.** The Main Office publishes a minimum and maximum markup per product and partner
type; the ITP configures whatever it likes inside them. Price stays competitive at the ceiling and
defensible at the floor, and the ITP keeps the autonomy it has now. Needs a `pricing_constraints`
table owned by level 0, validation at rule save, **and a clamp at quote time** — a corridor that
narrows later must bind rules that were saved before it existed, so save-time validation alone is not
enough.

**M3 — Commission.** The office alone sets what the customer pays; the ITP's earnings are a share of
*that* markup rather than an addition to it, so one fare costs the same everywhere and the brand holds
one price. This is how airline and OTA distribution actually works. It is the largest of the three
realistic options because `booking_price_layers` assumes **every row moves `running_total`**
(`2026_08_14_000002_create_booking_price_layers_table.php:45-47`); an earning that does not move the
sell needs a contribution kind on the layer. It also reopens C3 — Model A debits `cost_amount`, and a
commission model has to say whether that still holds.

**M4 — Exclusive rules.** A `mode` of `add` or `replace` on the rule. A replacing rule that matches
wins its level outright and suppresses the rest — the shape a negotiated corporate contract actually
has, where the agreement **is** the price rather than a discount off one. One column, plus a change to
`RuleMatcher::allMatches()`. Note what it costs conceptually: **`priority` becomes load-bearing
again**, because the matcher must stop at the first replacing rule, so the order control C5 removed
would have to come back for these rules.

**M5 — Agent discretion.** The engine's number becomes a suggestion; the agent may move the price at
the point of sale within an authorized band. This is the single most common reason a travel agency
wants pricing flexibility — matching a competitor's quote on the phone. It needs a manual layer with
an author, a reason and an audit entry; an RBAC permission distinct from `markup.edit`
(`config/rbac.php:257-272`); and a floor, so discretion cannot cross into selling below cost.

**M6 — Volume rebate.** Quote-time pricing does not change at all. The ITP earns a percentage of the
margin it produced once a period closes, credited to its wallet. `booking_price_layers` already
records margin per agency per booking and is indexed `(agency_id, level)` for exactly this question,
so the data exists today — this is a period job over `MarginReport` and a wallet credit.

### 4.3 The two-rung limit

**[EXISTING]** `StrategyResolver::chain()` (`:64`) prepends the Main Office and appends the booker,
and that is the whole chain. **An Outlet sitting under an ITP does not produce three levels** —
`agencies.parent_id` (`2026_08_10_000001_create_agencies_table.php:21`) is documented as reporting-only
and is deliberately not walked, because it is very likely unset on most rows and a walk would silently
skip the Main Office for exactly those agencies.

If a model requires an ITP to earn on its own outlets' bookings, that is a third rung. Per
[`01`](01-architecture.md) §12 it changes only `chain()` — the engine loops whatever list comes back
and has no idea how long it is — but it also changes what `AgencyPriceView` must fuse into `cost`, and
that is the part to check before committing.

---

## 5. Targeting a strategy per product

**[EXISTING]** A rule already carries `product` of `flight`, `hotel` or `*`, so targeting exists. What
is missing is the other half: **the product deciding which types are offered, and what they mean.**

**[RECOMMENDED]**

1. **A per-head fee is correct on flights and wrong on hotels.** The live system multiplied hotel
   markup by head count — two adults in one double room paid one room rate and two markups
   ([`tbohotel/02`](../tbohotel/02-live-reference-implementation.md) §4.7, and
   [`00-overview.md`](00-overview.md) §6 says plainly: *do not reintroduce a per-person option for
   hotels*). Once `per_pax` exists, nothing but the form stands between a user and that bug. A
   `CalcType::forProduct(BookingProduct)` map driving the select is a few lines and closes the class of
   bug permanently.
2. **The natural unit differs per product.** Flights price per pax and per segment; hotels per room and
   per night; transfers per vehicle; tours per pax again. A calculator set that ignores this pushes the
   mismatch onto whoever writes the rule.
3. **`applies_to` means different things per product** — see the caution in §1.
4. **Rounding wants a per-product default.** Rounding to ₱100 reads well on a hotel total and badly on
   a per-pax air fee. `pricing.rounding` is one global (`config/pricing.php`); a per-product default
   costs a config array and a lookup.
5. **New products still cost nothing here.** `product` is a validated string, not a DB enum, and
   product-specific fields live in `attributes` rather than as typed properties. Transfers and tours
   arrive as a factory method and a seeded value — no migration on live pricing.

---

## 6. Deliberately refused

**[EXISTING]** Three strategies the engine turns down on purpose. Each was decided rather than missed,
so each needs a business answer before it becomes code.

| Strategy | Current behaviour | What it would take |
| --- | --- | --- |
| **Discounts and promo pricing** | `PricingEngine::markupFor()` **throws** on a negative contribution rather than clamping it to zero (`:158`), and the validator refuses a negative `value` with *"A markup cannot be negative. Discounts are a separate mechanism."* (`StorePricingRuleRequest.php:86-92`). D4 recommended exactly this. | A separate mechanism, not a negative `value`. A discount has different authorization, audit and accounting from a markup — and a promo that cuts below the office's cut has to decide **whose margin pays for it**, which is a level-relationship question and belongs with §4. |
| **Compounding margins** | `PricingBasis::Running` exists as a column and the engine honours it (`:132`), but both forms force `net`. | Nothing technical — the support is there. It is a policy reversal of D1/D12, and it makes rule order load-bearing again. |
| **Currency and FX spread** | Everything is PHP; a supplier answering otherwise is already a guarded failure. | Out of scope by decision (D7 in [`00`](00-overview.md) §7). The standing rule stands: **markup must not become the place FX quietly enters the system.** |

---

## 7. Recommended order

**[RECOMMENDED]** Four increments, ordered by value per unit of risk, each shippable and testable
alone.

### Increment 1 — Finish the declared enum, open the forms

*No schema change.*

- Four calculators: `percentage_margin`, `per_pax`, `per_room_night`, `none`. Each is a class, a
  registry line and an entry in `implemented()`.
- `CalcType::forProduct()` gating the select, so `per_pax` cannot be chosen for a hotel (§5.1).
- Surface `matchers`, `valid_from`/`valid_to` and `applies_to` in both forms, and the floor/cap pair in
  the agency form (§1).

### Increment 2 — Widen what a rule can see

*Context only.*

- `daysToDeparture`, `agencyType`, `nights` and `segments` into `PricingContext::$attributes`.
- Unlocks advance-purchase pricing and partner-type pricing with **no engine or schema change** — the
  office charges outlets and ITPs differently from a single strategy.
- Add `agencies.tier` here if partner grades are wanted; the factory change is the same one.

### Increment 3 — `params`, then the multi-number types

*One column.*

- Add `params` JSON to `pricing_rules` and to `PricingRule::snapshot()` (§2.3).
- `tiered`, then `price_point`. Both are pure calculators once the column exists.
- The ladder preview runs the real engine, so tier boundaries can be checked against a sample net
  before a rule goes live.

### Increment 4 — Pick one office-to-ITP model

*Answer §8 first.*

- **M2 Corridor** if the goal is to keep ITP autonomy while protecting the brand price. Smallest
  change, largest control gain, composes with everything above.
- **M3 Commission** if the goal is one customer price across every partner. Larger, reaches the wallet
  and reopens C3, so it wants its own phase.
- **M4 Exclusive rules** whenever the first negotiated corporate account arrives, whichever of the two
  is chosen.

---

## 8. Business decisions this needs

**[UNRESOLVED]** None of these blocks §§1–3, which is deliberate: increments 1 to 3 are all additive
and none of them presumes an answer. They block increment 4.

| # | Decision | Why it matters |
| --- | --- | --- |
| **S1** | **May two ITPs sell the same fare at different prices?** | The root question. *Yes* keeps M1 and points at M2; *no* points at M3. Every other choice in §4 follows from this one. |
| **S2** | **When the house says "20%", is that markup or margin?** | ₱250 a booking on a ₱5,000 fare. This is D2 restated: both types should exist either way, but the form's default is a house convention and should be settled once, before rules are written against it. |
| **S3** | **May an ITP sell at zero margin?** | If not, M2's floor is mandatory rather than advisory, and it must bind rules saved before the floor existed — which is why §4.2 puts a clamp at quote time and not only at save. |
| **S4** | **Is markup retained on cancellation?** | D7, still open, and unrelated to which strategies get built — but M3 and M6 make it urgent, because it decides whose earnings are clawed back. |
| **S5** | **Is a discount ever wanted, and whose margin pays for it?** | §6. The answer determines whether the negative-markup refusal is permanent or a placeholder. |
