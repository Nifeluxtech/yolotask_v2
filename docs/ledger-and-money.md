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
- Lock order: wallet row (`SELECT … FOR UPDATE`), then the order/withdrawal
  row, then ledger accounts.
- Reconciliation (`Ledger::reconcile`, daily cron and the admin button):
  total debits = credits, every transaction balanced with ≥ 2 entries, every
  account's cached balance = sum of its entries, wallet caches = their
  accounts, held balances = open withdrawals; and (Phase 4) every escrow
  account = funded − spent − refunded, total escrow = total unspent budgets,
  spent/approved/pending counters = submissions, closed campaigns keep only
  the price of pending submissions, revenue = margins of approved tasks,
  reward hold = unreleased rewards. Mismatches are logged and the
  cron exits 2.
