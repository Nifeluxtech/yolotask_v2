<?php
declare(strict_types=1);
namespace YoloTask\Payments;

use YoloTask\Core\Logger;
use YoloTask\Support\Storage;

/**
 * Optional Paystack account-name lookup for the bank account page.
 *
 * Used only when payments config has paystack_name_lookup.secret_key;
 * otherwise the user's typed account name is kept and nothing is sent to
 * Paystack. The Paystack bank code for an AfrStar bank comes from
 * bank_code_map when listed there, else from Paystack's own bank list
 * (GET /bank?country=nigeria, cached for 24 h in runtime/cache) matched by
 * bank name. Any failure falls back to the typed name.
 */
final class PaystackClient
{
    private static function config(): array
    {
        $all = function_exists('yolotask_config') ? yolotask_config() : [];
        $cfg = $all['payments']['paystack_name_lookup'] ?? [];
        return is_array($cfg) ? $cfg : [];
    }

    public static function isConfigured(): bool
    {
        return trim((string) (self::config()['secret_key'] ?? '')) !== '';
    }

    private static function baseUrl(): string
    {
        $b = trim((string) (self::config()['base_url'] ?? ''));
        return rtrim($b !== '' ? $b : 'https://api.paystack.co', '/');
    }

    /** Account name from Paystack, or null when unavailable for any reason. */
    public static function resolveAccountName(string $accountNumber, string $afrstarBankCode): ?string
    {
        if (!self::isConfigured() || !function_exists('curl_init')) return null;
        $code = self::paystackCode($afrstarBankCode);
        if ($code === null) return null;
        $json = self::get('/bank/resolve?' . http_build_query(['account_number' => $accountNumber, 'bank_code' => $code]));
        $name = is_array($json) && !empty($json['status']) ? trim((string) ($json['data']['account_name'] ?? '')) : '';
        if ($name === '') {
            Logger::error('paystack.lookup_failed', []);
            return null;
        }
        return $name;
    }

    /** Paystack bank code for an AfrStar bank code, or null. */
    public static function paystackCode(string $afrstarBankCode): ?string
    {
        $map = is_array(self::config()['bank_code_map'] ?? null) ? self::config()['bank_code_map'] : [];
        if (isset($map[$afrstarBankCode]) && (string) $map[$afrstarBankCode] !== '') return (string) $map[$afrstarBankCode];
        $name = BankCodes::name($afrstarBankCode);
        if ($name === null) return null;
        $want = self::normalize($name);
        $best = null;
        foreach (self::banks() as $b) {
            $have = self::normalize((string) ($b['name'] ?? ''));
            if ($have === '' || empty($b['code'])) continue;
            if ($have === $want) return (string) $b['code'];
            if ($best === null && strlen($want) >= 4 && strlen($have) >= 4 && (str_contains($have, $want) || str_contains($want, $have))) $best = (string) $b['code'];
        }
        return $best;
    }

    /** Paystack's Nigerian bank list, cached for a day. */
    private static function banks(): array
    {
        $dir = Storage::dir('cache');
        $file = $dir !== null ? $dir . '/paystack-banks-' . substr(md5(self::baseUrl()), 0, 8) . '.json' : null;
        if ($file !== null && is_file($file) && filemtime($file) > time() - 86400) {
            $cached = json_decode((string) file_get_contents($file), true);
            if (is_array($cached)) return $cached;
        }
        $json = self::get('/bank?country=nigeria&perPage=200');
        $list = is_array($json) && !empty($json['status']) && is_array($json['data'] ?? null) ? array_values(array_map(static fn ($b) => ['name' => (string) ($b['name'] ?? ''), 'code' => (string) ($b['code'] ?? '')], $json['data'])) : [];
        if ($list !== [] && $file !== null) @file_put_contents($file, json_encode($list), LOCK_EX);
        return $list;
    }

    private static function normalize(string $name): string
    {
        $n = strtolower($name);
        $n = (string) preg_replace('/\b(bank|plc|limited|ltd|nigeria|nig|of|the|microfinance|mfb|mfbank|psb|international)\b/', ' ', $n);
        return (string) preg_replace('/[^a-z0-9]+/', '', $n);
    }

    private static function get(string $path): ?array
    {
        $ch = curl_init(self::baseUrl() . $path);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . trim((string) self::config()['secret_key'])],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 8,
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) Logger::error('paystack.http_failed', ['error' => curl_error($ch)]);
        curl_close($ch);
        $json = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($json) ? $json : null;
    }
}
