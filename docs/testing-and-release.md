
## AfrStar callback hub test
`tests/Integration/PaymentCallbacks/forwarder_test.py` (Phase 3 environment +
migration 0007 + openssl): starts a fake HTTPS downstream on 127.0.0.1:8496 and
checks JSON/form forwarding, status passthrough, timeout → 502, YTP/YTW never
forwarded, config absent → no forwarding, route validation (https only, no
private hosts), logging, the retry cron and the admin "Forwarded callbacks"
section. It removes `afrstar-forward.php` from the test config dir when done.
