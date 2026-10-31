<?php
/**
 * AfrStar callback hub (forwarder) example.
 *
 * AfrStar allows ONE collection and ONE payout callback URL per merchant.
 * When another app uses the same merchant, point AfrStar at YOLOTASK's hooks
 * and list that app here; YOLOTASK relays its callbacks untouched.
 *
 * Copy to the private config directory as afrstar-forward.php
 * (~/yolotask-v2-live/config-private/afrstar-forward.php, mode 0600).
 * No file = forwarding off (every callback is treated as YOLOTASK's).
 *
 * Rules:
 *  - routes are tried in order; the first `pattern` matching the callback's
 *    order_sn wins. YOLOTASK V2 references (YTP..., YTW...) are never
 *    forwarded whatever the pattern says.
 *  - `url` must be https on a public host (no IPs in private/loopback ranges,
 *    no localhost). Invalid routes are ignored and logged.
 *  - `timeout` is seconds (1-30, default 10). AfrStar waits about 5 s, so a
 *    slow downstream answer just means AfrStar retries.
 *  - The same URL receives both collection and payout callbacks; the receiving
 *    app verifies the signature with its own keys.
 *  - `name`: lowercase letters, digits, - and _ (shown on the admin page).
 */
return [
    'routes' => [
        [
            'name' => 'splug',
            'pattern' => '/^(DEP|WDL)-/',
            'url' => 'https://splug-theta.vercel.app/api/gateway',
            'timeout' => 10,
        ],
        // Later, e.g. YOLOTASK V1 (TOP-... references):
        // ['name' => 'v1', 'pattern' => '/^TOP-/', 'url' => 'https://v1.example.com/api/afrstar-callback', 'timeout' => 10],
    ],
];
