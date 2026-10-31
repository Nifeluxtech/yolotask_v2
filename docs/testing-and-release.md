
## AfrStar callback hub test
`tests/Integration/PaymentCallbacks/forwarder_test.py` (Phase 3 environment +
migration 0007 + openssl): starts a fake HTTPS downstream on 127.0.0.1:8496 and
checks JSON/form forwarding, status passthrough, timeout → 502, YTP/YTW never
forwarded, config absent → no forwarding, route validation (https only, no
private hosts), logging, the retry cron and the admin "Forwarded callbacks"
section. It removes `afrstar-forward.php` from the test config dir when done.

## Phase 5 appeals and reviewer queue test
`tests/Integration/Appeals/phase5_test.py` (Phase 4 environment + migration
0008 + the reviewer server on 8404, which `run-servers.sh` now starts):
appeal window (and changing it in settings), one appeal per rejection,
simultaneous appeal clicks, resubmit exclusion both ways, reviewer queue
order and appeal page contents, claim locking (other reviewer refused,
simultaneous claims, release, expiry + 5-minute cron), uphold, overturn
from escrow with triple-click idempotency, over-budget overturns from the
advertiser wallet (reward hold respected) and with other earners' slots held,
platform fallback with an advertiser debt (collect refused while empty,
collected after top-up), admin override both ways (payment reversal and
top-up return, refusal when the earner spent the reward), admin decisions
are final, the stale queue (claim, approve, reject, appeal of a reviewer
rejection), the advertiser quality flag (thresholds, window), emails,
portal authorization (earner/advertiser cannot reach reviewer routes,
reviewer cannot reach admin), CSRF on every new form, proof access for
reviewers, reconciliation and the read-only verification SQL.

Run every suite with `rate_limits` and `login_attempts` emptied first; also
run them against servers started with
`YT_PHP_ARGS="-d disable_functions=sodium_crypto_secretbox,sodium_crypto_secretbox_open"`.
