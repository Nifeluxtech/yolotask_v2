# Ledger and money

- Money is integer kobo (`BIGINT`) in PHP and the database; `Money\Money`
  converts at the edges ("500.00" for AfrStar, "₦1,250.00" for display).
- `Money\Ledger::post()` is the only code that changes balances. It runs inside
  the caller's transaction, refuses unbalanced postings, locks the touched
  `ledger_accounts` rows in id order, refuses to take a wallet account below
  zero, writes `ledger_entries` (with balance after) and updates cached
  balances. Each `ledger_transactions.idempotency_key` is unique; a repeat
  returns the original transaction.
- Every user has one NGN wallet (`wallets`, created lazily) with two ledger
  accounts: available and held (credit-normal). System accounts:
  `system:afrstar_clearing` (money at AfrStar, debit-normal) and
  `system:adjustments`.
- Postings: top-up = Dr clearing / Cr available; withdrawal request = Dr
  available / Cr held; reject or failed payout = Dr held / Cr available; paid
  payout = Dr held / Cr clearing; admin credit = Dr adjustments / Cr
  available (debit is the reverse).
- Phase 4: each funded campaign has its own credit-normal escrow account
  (`kind = campaign_escrow`, `campaign:<id>:escrow`); `system:revenue`
  (credit-normal) collects task margins and `system:reward_hold`
  (credit-normal) holds rewards during an optional hold window. Postings:
  fund = Dr advertiser available / Cr escrow; refund = Dr escrow / Cr
  advertiser available; approve = Dr escrow (price) / Cr earner available or
  reward_hold (reward) / Cr revenue (margin); release = Dr reward_hold / Cr
  earner available. `campaigns.funded_kobo/spent_kobo/refunded_kobo` cache
  the campaign side.
- Phase 5 (appeals): an overturned rejection is paid with the same approve
  posting, key `appeal-approve:<appealId>`. The campaign escrow pays when it
  has room beyond pending proofs and other earners' active slot holds
  (escrow − (pending + active reservations) × price). Otherwise the
  shortfall is first moved into the escrow (key `appeal-fund:<appealId>`,
  funding event `appeal_topup`, `funded_kobo` grows): Dr advertiser
  available (as much as the wallet has) and, for the rest, Dr
  `system:advertiser_receivable` (debit-normal, no negative) / Cr escrow;
  the platform-fronted part opens an `advertiser_debts` row. Such an
  approval is "over budget": recorded on the appeal (`escrow_kobo`,
  `wallet_kobo`, `platform_kobo`, `over_budget`), counted in
  `campaigns.extra_approved_count` (capacity = quantity + extra, so it never
  takes another earner's slot) and shown to the advertiser and admins.
  Admin reversal of an overturn: Dr earner available or `system:reward_hold`
  (reward) / Dr revenue (margin) / Cr escrow (key `appeal-reverse:<id>`),
  then the top-up goes back where it came from (key `appeal-unfund:<id>`,
  recorded as a refund event): advertiser wallet, receivable (debt
  cancelled), `system:adjustments` (debt had been written off) or the
  advertiser wallet (debt had been collected). Debts: collect = Dr advertiser
  available / Cr receivable (`debt-collect:<id>`), write off = Dr
  `system:adjustments` / Cr receivable (`debt-writeoff:<id>`).
  **Why a receivable and not `system:revenue`:** revenue must keep equalling
  the margins of approved tasks (a reconciliation rule); fronting from a
  separate receivable keeps that true and makes the advertiser's debt an
  explicit, reconcilable balance (receivable = open debts).
- Lock order: wallet row (`SELECT … FOR UPDATE`), then the order/withdrawal
  row, then ledger accounts.
- Reconciliation (`Ledger::reconcile`, daily cron and the admin button):
  total debits = credits, every transaction balanced with ≥ 2 entries, every
  account's cached balance = sum of its entries, wallet caches = their
  accounts, held balances = open withdrawals; and (Phase 4) every escrow
  account = funded − spent − refunded, total escrow = total unspent budgets,
  spent/approved/pending counters = submissions, closed campaigns keep only
  the price of pending submissions, revenue = margins of approved tasks,
  reward hold = unreleased rewards; and (Phase 5) every campaign's
  `extra_approved_count` = its over-budget overturned appeals and
  `system:advertiser_receivable` = open advertiser debts. Mismatches are
  logged and the cron exits 2.
