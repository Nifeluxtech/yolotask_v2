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
- Lock order: wallet row (`SELECT … FOR UPDATE`), then the order/withdrawal
  row, then ledger accounts.
- Reconciliation (`Ledger::reconcile`, daily cron and the admin button):
  total debits = credits, every transaction balanced with ≥ 2 entries, every
  account's cached balance = sum of its entries, wallet caches = their
  accounts, held balances = open withdrawals. Mismatches are logged and the
  cron exits 2.
