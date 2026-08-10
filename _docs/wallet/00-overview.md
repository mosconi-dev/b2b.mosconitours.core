# Wallet — the agency e-wallet

A prepaid balance per agency, topped up through a reviewed request cycle.
Namespaces: `App\Services\Wallet`, models `Wallet` / `WalletTransaction` / `WalletLoadRequest`.

## Design in one paragraph

**The wallet belongs to the agency, never to a user.** One balance per agency, every member draws on it,
and a member transferring out or being deleted changes nothing. Any member may raise a load request;
whoever holds `wallet.load.approve` decides it. **Who may do what is decided entirely by permissions** —
there is no privileged agency type and no hardcoded "only the office approves" rule. The only identity
rule is four-eyes: you cannot review your own request.

## Data model (migration `2026_08_10_000006`)

- **`wallets`** — `agency_id` (**unique**: exactly one per agency), `currency` (default PHP),
  `balance` decimal(14,2), `timestamps`.
- **`wallet_transactions`** — the append-only ledger: `wallet_id`, `agency_id` (denormalized for
  scoping), `direction` (`credit`\|`debit`), `amount`, `balance_after` (the running total, so the ledger
  reconciles on its own), nullable `source` morph (the load request today, a booking later),
  `description`, `user_id`, `created_at`. **Never updated or deleted** — a correction is a new opposing
  entry.
- **`wallet_load_requests`** — `reference` (`LR-XXXXXXXX`), `agency_id`, `wallet_id`, `amount`,
  `currency`, `status` (`LoadRequestStatus`), `payment_reference` (the requester's deposit/transfer ref),
  `remarks`, `requested_by`, `reviewed_by`, `reviewed_at`, `review_remarks`, and
  **`wallet_transaction_id` (unique, nullable)** — set once on approval.

All three use `BelongsToAgency`, so every list is agency-scoped like the rest of the admin area.

## Integrity — how a double credit is prevented

Four independent layers, because this is money:

1. **The status machine.** `LoadRequestStatus` mirrors `BookingStatus`: `pending` is the only
   non-terminal state. Approved, rejected and cancelled allow no transitions, so an approved request can
   never be approved again. Reversing one is a new ledger entry, not a status change.
2. **Re-read under lock.** `approve()` opens a transaction, re-selects the request `lockForUpdate()` and
   re-checks the status — two reviewers clicking at the same instant both passed the first check; the
   loser gets "already approved".
3. **A unique index** on `wallet_transaction_id` — the database refuses a second entry even if the
   application logic were bypassed entirely.
4. **The policy** refuses a replay outright, because the row is no longer pending.

Balance integrity: `wallets.balance` is a *cached* total and `wallet_transactions` is authoritative.
`WalletService::post()` writes both inside one transaction with the wallet row locked, so they cannot
drift and two concurrent debits cannot both read the same balance and overdraw. `ledgerBalance()` sums
the ledger independently — a test walks amounts chosen to expose float drift and asserts the two agree.

**All arithmetic is bcmath on decimal strings; money never touches a float.** `normalize()` strips
thousands separators first, since bcmath would read `"1,500.00"` as `1`.

## Permissions

| Ability | Meaning |
| --- | --- |
| `wallet.view` | See your agency's balance and ledger (`/wallet`) |
| `wallet.adjust` | Post a manual adjustment — **moves money with no request and no second pair of eyes** |
| `wallet.load.view` | See the load-request queue |
| `wallet.load.create` | Raise a top-up request |
| `wallet.load.approve` | Decide a request — approve **or** reject (two outcomes of one act) |
| `wallet.load.cancel` | Withdraw a pending request |

`WalletLoadRequestPolicy` adds only what a permission cannot express: agency scope, the pending check,
and four-eyes. `create` additionally requires an agency — platform staff have no wallet to load.

## HTTP surface

- **Top bar** — `<x-wallet-balance />` (`App\View\Components\WalletBalance`) shows the agency balance on
  every page, red when overdrawn, linking to `/wallet`. It renders only for a user with an agency **and**
  `wallet.view`, so platform staff see nothing. The lookup is **read-only** — a GET must never create a
  wallet row, and an agency that has never loaded genuinely shows 0.00. One indexed query per render
  (`wallets.agency_id` is unique); cache it in `WalletService::post()` if that ever shows up in a profile.
- **`/wallet`** — balance + ledger for the signed-in user's agency. Platform staff get an explanation
  rather than a zero balance that reads like empty funds.
- **`/wallet/requests`** — the queue, scoped and status-filterable, with Approve / Reject / Cancel
  inline. `create` + `store` raise one.

## Audit

`wallet.load_requested`, `wallet.load_approved` (with the resulting balance), `wallet.load_rejected`,
`wallet.load_cancelled` — all through the existing `AuditLogger`, so they inherit agency scoping.

## Corrections

Nothing on the ledger is ever edited or deleted. A correction is a **new entry**, so the mistake and
its fix both stay on the record.

**How a discrepancy is handled depends on when it is caught:**

| When | What happens |
| --- | --- |
| Before approval | **Reject** the request. The agency reissues a corrected one. No money moved. |
| After approval | **Manual adjustment** — an offsetting debit, behind `wallet.adjust`. |

An adjustment is a credit or debit with a **required reason**, recorded on the ledger and in the audit
trail. The load request's status is never rewound: approved stays terminal, which is what makes a double
credit impossible. The ledger tells the story instead.

> Entry-level *reversal* (undoing one specific ledger row) was built and then removed — it was a second
> correction mechanism covering ground manual adjustment already covers. See migrations
> `2026_08_10_000007` and `..._000008`.

### Adjustments may go negative

`post()` takes `allowNegative`, set only by `adjust()`. If 5,000 was credited in error and 3,000 already
spent, the claw-back is still owed — refusing to record it would leave the books wrong rather than
merely uncomfortable, so the balance goes to −3,000 and is shown in red. Ordinary operational debits
keep the insufficient-funds guard.

### Where it lives

The agency hub gains a **Wallet** tab (`/admin/agencies/{agency}?tab=wallet`) with the balance, the
ledger and the adjustment form — this is how the office reaches an agency's wallet at all, since
`/wallet` only ever shows the signed-in user's own. Both surfaces share `wallet/_ledger.blade.php` and
`wallet/_adjust.blade.php`.

Audit event: `wallet.adjusted`, with the reason and resulting balance.

> **`wallet.adjust` is effectively the right to mint money.** Unlike a load request there is no
> request/approve split, so one holder can credit a wallet unilaterally. Keep it on office/platform
> roles; do not grant it to agency roles.

## Spending — bookings draw on the wallet

`BookingService::createFromQuote()` debits the booker's agency wallet for the booking's
`total_amount` (fare + ancillaries), with the booking itself as the ledger entry's `source`.

- **The TBO reads stay outside the transaction**; only the booking row and its charge go in it. An
  insufficient balance therefore rolls the booking back rather than leaving one nobody paid for.
- **Insufficient funds is re-thrown as a `BookingException`**, so it travels the same path as every
  other booking failure — including the 422 JSON the wizard expects — instead of rendering as an
  unrelated wallet error. The agent sees the shortfall: *"Insufficient wallet balance: 100.00
  available, 6,400.00 required."*
- **Platform staff are not charged.** No agency means no wallet; they are the operator, not a customer
  of the balance.

### Refunds

`transitionTo()` gives the charge back when a booking reaches **Failed**, **Cancelled** or
**Refunded** — without one, a failed booking would silently eat the funds.

- The refunded amount is read from **the original ledger entry**, not from the booking, so the two
  cannot drift.
- Refunded **at most once**: `Ticketed → Cancelled → Refunded` walks through two refunding statuses and
  only the first moves money (`Booking::wasRefundedToWallet()`).
- A booking that was never charged (platform staff, or a zero total) has nothing to give back.

> **Cancellation is refunded in full.** There is no airline-penalty or service-fee model, so a cancelled
> ticket returns the whole amount. Where a real penalty applies, post the difference as a manual
> adjustment until fees are modelled.

## Not built yet

- **Notifications.** Nobody is told a request is waiting; today the queue must be checked.
- **Cancellation fees**, as above.
