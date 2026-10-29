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
    // Optional Paystack account-name lookup on the bank account page.
    // Leave secret_key empty to skip the lookup (users type the name).
    'paystack_name_lookup' => [
        'secret_key' => '',
        // AfrStar bank code => Paystack bank code, e.g. '80000009' => '058'.
        'bank_code_map' => [],
    ],
];
