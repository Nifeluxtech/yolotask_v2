<?php
/**
 * Payments configuration example.
 *
 * Copy to the private config directory as payments.local.php
 * (e.g. ~/yolotask-v2-live/config-private/payments.local.php, mode 0600).
 * Never commit the real file or the key file.
 *
 * AfrStar credentials live in a separate file next to it:
 *   config-private/afrstar.key   (mode 0600)
 * containing ONE line, exactly:
 *   MERCHANT_SN,MERCHANT_AK,MERCHANT_SK
 * Whitespace around each value is trimmed. If payments.local.php is absent
 * the application still reads afrstar.key directly with the defaults below.
 * When neither yields all three values, top-up and withdrawal pages show
 * "Payments are not available yet" and nothing is sent to AfrStar.
 */
$afrstar = ['merchant_sn' => '', 'merchant_ak' => '', 'merchant_sk' => ''];
$keyFile = __DIR__ . '/afrstar.key';
if (is_file($keyFile) && is_readable($keyFile)) {
    $parts = array_map('trim', explode(',', trim((string) file_get_contents($keyFile))));
    if (count($parts) === 3) {
        [$afrstar['merchant_sn'], $afrstar['merchant_ak'], $afrstar['merchant_sk']] = $parts;
    }
}

return [
    'afrstar' => $afrstar + [
        // Gateway base URL. Override only for local testing against a fake server.
        'base_url' => 'https://ngn.afrstar.com',
        // Seconds to wait for AfrStar API responses.
        'timeout' => 15,
        // Optional: only accept callbacks from these source IPs (empty = any;
        // signatures are always verified).
        'callback_ips' => [],
    ],
    // Korapay (migration 0021). The secret key is required; payouts also need
    // the public key (Korapay's bank list only accepts it). Instead of this
    // block you can put the secret key alone in config-private/korapay.key
    // (one line, mode 0600) and the public key in config-private/korapay.pub. Choose which gateway handles top-ups and which
    // handles withdrawals in Admin → Money settings → Payment gateway.
    // Set the webhook URL in the Korapay dashboard (Settings → API Configuration):
    //   https://yolotask.com.ng/hooks/korapay/webhook.php
    // and whitelist this server's IP for payouts.
    'korapay' => [
        'secret_key' => '',            // sk_live_… (sk_test_… for the sandbox)
        'public_key' => '',            // pk_live_…; needed for payouts (Korapay's bank list)
        // Optional: AfrStar bank code => Korapay bank code, for banks the name
        // match misses, e.g. '80000030' => '999992'. Others are matched by name
        // against Korapay's bank list (cached 24 h in runtime/cache).
        'bank_code_map' => [],
        'channels' => ['bank_transfer', 'card'], // checkout payment methods
        'timeout' => 20,
        'base_url' => 'https://api.korapay.com', // only change for local testing
    ],
    // Optional Paystack account-name lookup on the bank account page.
    // Leave secret_key empty to skip the lookup (users type the name and
    // nothing is sent to Paystack). With a key, the name returned by the
    // bank replaces the typed one and the account shows as confirmed.
    'paystack_name_lookup' => [
        'secret_key' => '',
        // Optional overrides: AfrStar bank code => Paystack bank code, e.g.
        // '80000009' => '058'. Banks not listed are matched by name against
        // Paystack's bank list (cached 24 h in runtime/cache).
        'bank_code_map' => [],
        // Only change for local testing against a fake server.
        'base_url' => 'https://api.paystack.co',
    ],
];
