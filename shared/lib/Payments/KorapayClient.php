<?php
declare(strict_types=1);
namespace YoloTask\Payments;

use YoloTask\Core\Logger;
use YoloTask\Money\Money;
use YoloTask\Support\Storage;

/**
 * Korapay API client (https://developers.korapay.com), migration 0021.
 *
 *  - Pay-ins use Checkout Redirect: POST /merchant/api/v1/charges/initialize
 *    returns a checkout_url; the order reference is our order_sn.
 *  - Pay-in status: GET /merchant/api/v1/charges/{reference}.
 *  - Payouts: POST /merchant/api/v1/transactions/disburse (bank_account, NGN).
 *  - Payout status: GET /merchant/api/v1/transactions/{reference}.
 *  - Webhooks carry x-korapay-signature = HMAC-SHA256(json(data), secret key).
 *
 * Nothing is credited or marked paid from a webhook body alone: the webhook
 * only tells us WHICH reference to look at, and the status and amount always
 * come from the verify endpoints above (KorapayProcessor).
 *
 * Credentials come only from the private config: payments.local.php
 * ('korapay' => ['secret_key' => 'sk_live_…']) or, when that file has no
 * korapay block, a one-line config-private/korapay.key holding the secret key.
 */
final class KorapayClient
{
    public const DEFAULT_BASE = 'https://api.korapay.com';

    /** @return array{secret_key:string,public_key:string,base_url:string,timeout:int,bank_code_map:array<string,string>,channels:list<string>} */
    public static function config(): array
    {
        $all = function_exists('yolotask_config') ? yolotask_config() : [];
        $cfg = is_array($all['payments']['korapay'] ?? null) ? $all['payments']['korapay'] : [];
        if (trim((string) ($cfg['secret_key'] ?? '')) === '') {
            $dir = (string) ($all['_config_dir'] ?? '');
            $file = $dir !== '' ? $dir . '/korapay.key' : '';
            if ($file !== '' && is_file($file) && is_readable($file)) {
                $cfg['secret_key'] = trim((string) file_get_contents($file));
            }
        }
        if (trim((string) ($cfg['public_key'] ?? '')) === '') {
            $dir = (string) ($all['_config_dir'] ?? '');
            $file = $dir !== '' ? $dir . '/korapay.pub' : '';
            if ($file !== '' && is_file($file) && is_readable($file)) {
                $cfg['public_key'] = trim((string) file_get_contents($file));
            }
        }
        $channels = is_array($cfg['channels'] ?? null) ? array_values(array_filter(array_map('strval', $cfg['channels']))) : ['bank_transfer', 'card'];
        return [
            'secret_key' => trim((string) ($cfg['secret_key'] ?? '')),
            'public_key' => trim((string) ($cfg['public_key'] ?? '')),
            'base_url' => rtrim((string) (($cfg['base_url'] ?? '') ?: self::DEFAULT_BASE), '/'),
            'timeout' => max(3, min(30, (int) ($cfg['timeout'] ?? 20))),
            'bank_code_map' => is_array($cfg['bank_code_map'] ?? null) ? array_map('strval', $cfg['bank_code_map']) : [],
            'channels' => $channels,
        ];
    }

    public static function isConfigured(): bool
    {
        return str_starts_with(self::config()['secret_key'], 'sk_');
    }

    /** True for sk_test_ keys (sandbox). Shown on the admin settings page. */
    public static function isTestMode(): bool
    {
        return str_starts_with(self::config()['secret_key'], 'sk_test_');
    }

    /** The public webhook URL Korapay must call (also sent as notification_url). */
    public static function webhookUrl(): string
    {
        $base = function_exists('yolotask_portal_url') ? yolotask_portal_url('main') : 'https://yolotask.com.ng';
        return rtrim($base, '/') . '/hooks/korapay/webhook.php';
    }

    /**
     * Start a Checkout Redirect charge.
     * @return array{reference:string,checkout_url:string}
     */
    public static function initializeCharge(string $reference, int $amountKobo, string $name, string $email, string $narration, string $redirectUrl): array
    {
        $c = self::requireConfig();
        $body = [
            'amount' => self::nairaNumber($amountKobo),
            'currency' => 'NGN',
            'reference' => $reference,
            'redirect_url' => $redirectUrl,
            'notification_url' => self::webhookUrl(),
            'narration' => mb_substr($narration, 0, 100),
            'channels' => $c['channels'],
            'merchant_bears_cost' => true,
            'customer' => array_filter(['email' => $email, 'name' => mb_substr($name, 0, 80)], static fn ($v) => $v !== ''),
        ];
        $data = self::request('POST', '/merchant/api/v1/charges/initialize', $body);
        $url = (string) ($data['checkout_url'] ?? '');
        if ($url === '' || !preg_match('#^https://#i', $url)) throw new KorapayException('Korapay did not return a payment link.', true);
        return ['reference' => (string) ($data['reference'] ?? $reference), 'checkout_url' => $url];
    }

    /**
     * Query a charge by our reference.
     * @return array{status:string,amount_kobo:?int,amount_paid_kobo:?int,currency:string,payment_reference:string,message:string}
     *   status is Korapay's own word: success, failed, processing, pending, expired, …
     */
    public static function verifyCharge(string $reference, ?int $timeout = null): array
    {
        $data = self::request('GET', '/merchant/api/v1/charges/' . rawurlencode($reference), null, $timeout);
        return [
            'status' => strtolower(trim((string) ($data['status'] ?? ''))),
            'amount_kobo' => CallbackVerifier::amount($data['amount'] ?? null),
            'amount_paid_kobo' => CallbackVerifier::amount($data['amount_paid'] ?? null),
            'currency' => strtoupper((string) ($data['currency'] ?? '')),
            'payment_reference' => mb_substr((string) (($data['payment_reference'] ?? '') ?: ($data['transaction_reference'] ?? '')), 0, 120),
            'message' => mb_substr((string) ($data['message'] ?? ($data['description'] ?? '')), 0, 255),
        ];
    }

    /**
     * Send money to a Nigerian bank account.
     * @return array{status:string,reference:string,message:string}
     */
    public static function disburse(string $reference, int $amountKobo, string $korapayBankCode, string $accountNumber, string $accountName, string $email, string $narration): array
    {
        self::requireConfig();
        $data = self::request('POST', '/merchant/api/v1/transactions/disburse', [
            'reference' => $reference,
            'destination' => [
                'type' => 'bank_account',
                'amount' => self::nairaNumber($amountKobo),
                'currency' => 'NGN',
                'narration' => mb_substr($narration, 0, 100),
                'bank_account' => ['bank' => $korapayBankCode, 'account' => $accountNumber],
                'customer' => array_filter(['name' => mb_substr($accountName, 0, 80), 'email' => $email], static fn ($v) => $v !== ''),
            ],
        ]);
        return [
            'status' => strtolower(trim((string) ($data['status'] ?? ''))),
            'reference' => (string) ($data['reference'] ?? $reference),
            'message' => mb_substr((string) ($data['message'] ?? ''), 0, 255),
        ];
    }

    /**
     * Query a payout by our reference.
     * @return array{status:string,amount_kobo:?int,message:string,trace_id:string}
     */
    public static function verifyPayout(string $reference, ?int $timeout = null): array
    {
        $data = self::request('GET', '/merchant/api/v1/transactions/' . rawurlencode($reference), null, $timeout);
        return [
            'status' => strtolower(trim((string) ($data['status'] ?? ''))),
            'amount_kobo' => CallbackVerifier::amount($data['amount'] ?? null),
            'message' => mb_substr((string) ($data['message'] ?? ''), 0, 255),
            'trace_id' => mb_substr((string) ($data['trace_id'] ?? ''), 0, 120),
        ];
    }

    /** NGN balance (available) in kobo. */
    public static function balance(int $timeout = 6): int
    {
        $data = self::request('GET', '/merchant/api/v1/balances', null, $timeout);
        $ngn = is_array($data['NGN'] ?? null) ? $data['NGN'] : [];
        return CallbackVerifier::amount($ngn['available_balance'] ?? '0') ?? 0;
    }

    /**
     * Webhook authenticity: x-korapay-signature is HMAC-SHA256 of the JSON of
     * the `data` object, keyed with the secret key. JSON encoders differ on
     * slash escaping, so both common encodings are accepted; the raw `data`
     * substring is tried first.
     */
    public static function verifySignature(string $rawBody, string $signature): bool
    {
        $c = self::config();
        $signature = strtolower(trim($signature));
        if ($c['secret_key'] === '' || $signature === '' || !preg_match('/^[a-f0-9]{64}$/', $signature)) return false;
        $candidates = [];
        $rawData = self::rawDataObject($rawBody);
        if ($rawData !== null) $candidates[] = $rawData;
        $prev = ini_get('serialize_precision');
        ini_set('serialize_precision', '-1');
        try {
            $json = json_decode($rawBody, true);
            if (is_array($json) && array_key_exists('data', $json)) {
                foreach ([0, JSON_UNESCAPED_SLASHES, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE] as $flags) {
                    $enc = json_encode($json['data'], $flags);
                    if (is_string($enc)) $candidates[] = $enc;
                }
            }
        } finally {
            ini_set('serialize_precision', (string) $prev);
        }
        foreach (array_unique($candidates) as $candidate) {
            if (hash_equals(hash_hmac('sha256', $candidate, $c['secret_key']), $signature)) return true;
        }
        return false;
    }

    /** The literal `"data": {...}` object from a raw JSON body, or null. */
    private static function rawDataObject(string $raw): ?string
    {
        if (!preg_match('/"data"\s*:\s*\{/', $raw, $m, PREG_OFFSET_CAPTURE)) return null;
        $start = $m[0][1] + strlen($m[0][0]) - 1;
        $depth = 0;
        $inString = false;
        $len = strlen($raw);
        for ($i = $start; $i < $len; $i++) {
            $ch = $raw[$i];
            if ($inString) {
                if ($ch === '\\') { $i++; continue; }
                if ($ch === '"') $inString = false;
                continue;
            }
            if ($ch === '"') { $inString = true; continue; }
            if ($ch === '{') $depth++;
            if ($ch === '}' && --$depth === 0) return substr($raw, $start, $i - $start + 1);
        }
        return null;
    }

    /**
     * Korapay bank code for an AfrStar bank code (bank_accounts store AfrStar
     * codes). bank_code_map in config wins; otherwise Korapay's own Nigerian
     * bank list (cached 24 h) is matched by bank name. Null when unknown.
     */
    public static function bankCode(string $afrstarBankCode): ?string
    {
        $map = self::config()['bank_code_map'];
        if (isset($map[$afrstarBankCode]) && $map[$afrstarBankCode] !== '') return $map[$afrstarBankCode];
        $name = BankCodes::name($afrstarBankCode);
        if ($name === null) return null;
        $want = self::normalize($name);
        if ($want === '') return null;
        $best = null;
        foreach (self::banks() as $b) {
            $have = self::normalize($b['name']);
            if ($have === '' || $b['code'] === '') continue;
            if ($have === $want) return $b['code'];
            if ($best === null && strlen($want) >= 4 && strlen($have) >= 4 && (str_contains($have, $want) || str_contains($want, $have))) $best = $b['code'];
        }
        return $best;
    }

    /** Why the last banks() call returned no list ('' when it did not fail). */
    public static string $banksError = '';

    /** @return list<array{name:string,code:string}> */
    public static function banks(): array
    {
        self::$banksError = '';
        $c = self::config();
        $dir = class_exists(Storage::class) ? Storage::dir('cache') : null;
        $file = $dir !== null ? $dir . '/korapay-banks-' . substr(md5($c['base_url']), 0, 8) . '.json' : null;
        if ($file !== null && is_file($file) && filemtime($file) > time() - 86400) {
            $cached = json_decode((string) file_get_contents($file), true);
            if (is_array($cached) && $cached !== []) return $cached;
        }
        $list = [];
        try {
            $data = self::request('GET', '/merchant/api/v1/misc/banks?countryCode=NG', null, 8, $c['public_key'] !== '' ? $c['public_key'] : null);
            foreach ($data as $b) {
                if (is_array($b)) $list[] = ['name' => (string) ($b['name'] ?? ''), 'code' => (string) ($b['code'] ?? '')];
            }
        } catch (KorapayException $e) {
            Logger::error('korapay.banks_failed', ['message' => $e->getMessage()]);
            self::$banksError = $c['public_key'] === ''
                ? 'Korapay\'s bank list needs your Korapay public key. Save it as korapay.pub in the private config folder.'
                : 'Korapay\'s bank list could not be loaded (' . $e->getMessage() . ').';
        }
        if ($list !== [] && $file !== null) @file_put_contents($file, json_encode($list), LOCK_EX);
        return $list;
    }

    private static function normalize(string $name): string
    {
        $n = strtolower($name);
        $n = str_replace(['(uba)', 'gtbank', 'gtco'], ['', 'guaranty trust', 'guaranty trust'], $n);
        $n = (string) preg_replace('/\b(bank|plc|limited|ltd|nigeria|nig|of|the|microfinance|mfb|mfbank|psb|international|company)\b/', ' ', $n);
        return (string) preg_replace('/[^a-z0-9]+/', '', $n);
    }

    /** Naira as a number with at most two decimals (Korapay wants a JSON number). */
    private static function nairaNumber(int $kobo): int|float
    {
        return $kobo % 100 === 0 ? intdiv($kobo, 100) : round($kobo / 100, 2);
    }

    private static function requireConfig(): array
    {
        if (!self::isConfigured()) throw new KorapayException('Payments are not available yet.', true);
        return self::config();
    }

    /**
     * Call Korapay and return `data`. Throws KorapayException; ->definitive
     * is true when Korapay clearly refused (4xx with status=false), false
     * when the outcome is unknown (network error, timeout, 5xx).
     */
    private static function request(string $method, string $path, ?array $body = null, ?int $timeout = null, ?string $key = null): array
    {
        $c = self::config();
        if (!function_exists('curl_init')) throw new KorapayException('The PHP curl extension is required for payments.', true);
        $key ??= $c['secret_key'];
        if ($key === '') throw new KorapayException('Payments are not available yet.', true);
        $ch = curl_init($c['base_url'] . $path);
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $timeout ?? $c['timeout'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            // Korapay's payout IP whitelist takes IPv4 addresses; api.korapay.com also has
            // IPv6 records, so force IPv4 or the server may call out from an unlisted IPv6 address.
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        ];
        if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $localIp = (string) curl_getinfo($ch, CURLINFO_LOCAL_IP);
        curl_close($ch);
        $route = $method === 'GET' ? (string) preg_replace('#/(charges|transactions)/[^/?]+$#', '/$1/{ref}', strtok($path, '?') ?: $path) : (strtok($path, '?') ?: $path);
        if ($raw === false || $errno !== 0) {
            Logger::error('korapay.network_error', ['path' => $route, 'curl_errno' => $errno]);
            throw new KorapayException('Korapay could not be reached. Please try again shortly.', $errno === CURLE_COULDNT_CONNECT || $errno === CURLE_COULDNT_RESOLVE_HOST);
        }
        $json = json_decode((string) $raw, true);
        if (!is_array($json)) {
            Logger::error('korapay.http_error', ['path' => $route, 'http' => $http]);
            throw new KorapayException('Korapay returned an unexpected response.', false);
        }
        if ($http >= 500) {
            Logger::error('korapay.http_error', ['path' => $route, 'http' => $http]);
            throw new KorapayException('Korapay returned an unexpected response.', false);
        }
        if ($http < 200 || $http >= 300 || empty($json['status'])) {
            $message = mb_substr((string) ($json['message'] ?? 'Request declined'), 0, 160);
            Logger::error('korapay.api_error', ['path' => $route, 'http' => $http, 'message' => $message, 'local_ip' => $localIp]);
            // 4xx with status=false is a clear refusal; anything else stays "unknown".
            throw new KorapayException('Korapay declined the request: ' . $message, $http >= 400 && $http < 500);
        }
        $data = $json['data'] ?? [];
        if (!is_array($data)) return [];
        return $data;
    }
}
