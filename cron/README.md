# Cron

cPanel → Cron Jobs, as the account user. Logs go to the private runtime directory.

    15 2 * * *          /opt/cpanel/ea-php84/root/usr/bin/php $HOME/yolotask-v2-live/current/cron/daily.php >> $HOME/yolotask-v2-live/runtime/logs/cron-daily.log 2>&1
    0,15,30,45 * * * *  /opt/cpanel/ea-php84/root/usr/bin/php $HOME/yolotask-v2-live/current/cron/every-fifteen-minutes.php >> $HOME/yolotask-v2-live/runtime/logs/cron-15min.log 2>&1
    0-59/5 * * * *      /opt/cpanel/ea-php84/root/usr/bin/php $HOME/yolotask-v2-live/current/cron/every-five-minutes.php >> $HOME/yolotask-v2-live/runtime/logs/cron-5min.log 2>&1
    5 * * * *           /opt/cpanel/ea-php84/root/usr/bin/php $HOME/yolotask-v2-live/current/cron/hourly.php >> $HOME/yolotask-v2-live/runtime/logs/cron-hourly.log 2>&1

Optional (housekeeping only; the 15-minute job does the same work):

    * * * * *           /opt/cpanel/ea-php84/root/usr/bin/php $HOME/yolotask-v2-live/current/cron/every-minute.php >> $HOME/yolotask-v2-live/runtime/logs/cron-minute.log 2>&1

- `daily.php`: removes expired sessions, codes, links and throttle rows,
  expires unpaid AfrStar top-ups (no money moves) and runs the full ledger
  reconciliation (wallets, withdrawals, campaign escrow, revenue, reward
  hold, over-budget appeal approvals, advertiser receivable = open advertiser
  debts). Exit 2 = mismatch.
- `every-five-minutes.php`: re-runs AfrStar callbacks that were logged but
  never finished, expires unpaid top-ups, flags payouts with no callback
  after `money.payout_stuck_hours` for review, and (Phase 5) deletes expired
  reviewer claims so appeals and stale proofs return to the queue.
  Single-instance lock.
- `every-fifteen-minutes.php`: expires task reservations, auto-approves
  submissions past their auto-approve time, ends campaigns past their end
  date (refunding unused escrow), reports the stale and open-appeal counts.
  Single-instance lock.
- `hourly.php`: releases held task rewards (only when a reward hold is
  set) and runs the campaign escrow reconciliation (exit 2 on mismatch).
- `every-minute.php`: marks expired task reservations.

Phase 5 adds no new cron lines.

Every job is idempotent: money moves use unique ledger keys and re-check
state under row locks, so a repeated or overlapping run never pays twice.
