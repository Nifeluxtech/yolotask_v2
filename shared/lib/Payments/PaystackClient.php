<?php
declare(strict_types=1);
namespace YoloTask\Payments;

use YoloTask\Core\Logger;

/**
 * Optional Paystack account-name lookup for the bank account page.
 * Used only when payments config has paystack_name_lookup.secret_key and a
 * mapping from the AfrStar bank code to Paystack's bank code; otherwise the
 * user's typed account name is kept.
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

    /** Account name from Paystack, or null when unavailable for any reason. */
    public static function resolveAccountName(string $accountNumber, string $afrstarBankCode): ?string
    {
        $cfg = self::config();
        $map = is_array($cfg['bank_code_map'] ?? null) ? $cfg['bank_code_map'] : [];
        $code = (string) ($map[$afrstarBankCode] ?? '');
        if ($code === '' || !function_exists('curl_init')) return null;
        $ch = curl_init('https://api.paystack.co/bank/resolve?' . http_build_query(['account_number' => $accountNumber, 'bank_code' => $code]));
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . trim((string) $cfg['secret_key'])],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 8,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
        $json = is_string($raw) ? json_decode($raw, true) : null;
        $name = is_array($json) && !empty($json['status']) ? trim((string) ($json['data']['account_name'] ?? '')) : '';
        if ($name === '') {
            Logger::error('paystack.lookup_failed', []);
            return null;
        }
        return $name;
    }
}
