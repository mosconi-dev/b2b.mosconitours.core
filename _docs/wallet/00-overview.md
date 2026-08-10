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
| `wallet.load.view` | See the load-request queue |
| `wallet.load.create` | Raise a top-up request |
| `wallet.load.approve` | Decide a request — approve **or** reject (two outcomes of one act) |
| `wallet.load.cancel` | Withdraw a pending request |

`WalletLoadRequestPolicy` adds only what a permission cannot express: agency scope, the pending check,
and four-eyes. `create` additionally requires an agency — platform staff have no wallet to load.

## HTTP surface

- **`/wallet`** — balance + ledger for the signed-in user's agency. Platform staff get an explanation
  rather than a zero balance that reads like empty funds.
- **`/wallet/requests`** — the queue, scoped and status-filterable, with Approve / Reject / Cancel
  inline. `create` + `store` raise one.

## Audit

`wallet.load_requested`, `wallet.load_approved` (with the resulting balance), `wallet.load_rejected`,
`wallet.load_cancelled` — all through the existing `AuditLogger`, so they inherit agency scoping.

## Not built yet

- **Spending.** Nothing debits the wallet — bookings do not draw on it. `WalletService::debit()` exists,
  is tested (including the insufficient-funds refusal), and takes a `source` morph, so wiring booking
  payment is additive.
- **Manual adjustment.** There is no way to correct a load approved in error; the intended shape is a
  `wallet.adjust` permission over `debit()`, posting an opposing entry rather than editing history.
- **Notifications.** Nobody is told a request is waiting; today the queue must be checked.
