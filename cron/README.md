# Cron

Only `daily.php` is in use (cPanel, as the account user):

    15 2 * * * /opt/cpanel/ea-php84/root/usr/bin/php $HOME/yolotask-v2-live/current/cron/daily.php >> $HOME/yolotask-v2-live/runtime/logs/cron-daily.log 2>&1

It removes expired sessions, codes, links and throttle rows, expires unpaid
AfrStar top-up orders older than the configured window (no money moves) and
runs the ledger reconciliation. Exit code 2 means a reconciliation mismatch
(details in the log). The other cron files are placeholders.
