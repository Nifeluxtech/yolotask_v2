<?php
declare(strict_types=1);
namespace YoloTask\Payments;

use RuntimeException;
use YoloTask\Core\Logger;
use YoloTask\Money\Money;

/**
 * AfrStar Nigeria API v2 client (docs/providers/afrstar-v2.md).
 * JSON POST, code 0 = success, sign = md5(ordered values + merchant_sk).
 * Credentials come from the private config only (payments.local.php or
 * afrstar.key); nothing is hardcoded.
 */
final class AfrstarClient
{
    public const DEFAULT_BASE = 'https://ngn.afrstar.com';

    /** @return array{merchant_sn:string,merchant_ak:string,merchant_sk:string,base_url:string,timeout:int,callback_ips:array} */
    public static function config(): array
    {
        $all = function_exists('yolotask_config') ? yolotask_config() : [];
        $cfg = is_array($all['payments']['afrstar'] ?? null) ? $all['payments']['afrstar'] : null;
        if ($cfg === null) {
            // No payments.local.php: read the one-line key file directly.
            $cfg = [];
            $dir = (string) ($all['_config_dir'] ?? '');
            $file = $dir !== '' ? $dir . '/afrstar.key' : '';
            if ($file !== '' && is_file($file) && is_readable($file)) {
                $parts = array_map('trim', explode(',', trim((string) file_get_contents($file))));
                if (count($parts) === 3) $cfg = ['merchant_sn' => $parts[0], 'merchant_ak' => $parts[1], 'merchant_sk' => $parts[2]];
            }
        }
        return [
            'merchant_sn' => trim((string) ($cfg['merchant_sn'] ?? '')),
            'merchant_ak' => trim((string) ($cfg['merchant_ak'] ?? '')),
            'merchant_sk' => trim((string) ($cfg['merchant_sk'] ?? '')),
            'base_url' => rtrim((string) (($cfg['base_url'] ?? '') ?: self::DEFAULT_BASE), '/'),
            'timeout' => max(3, min(30, (int) ($cfg['timeout'] ?? 15))),
            'callback_ips' => is_array($cfg['callback_ips'] ?? null) ? array_values(array_map('strval', $cfg['callback_ips'])) : [],
        ];
    }

    public static function isConfigured(): bool
    {
        $c = self::config();
        return $c['merchant_sn'] !== '' && $c['merchant_ak'] !== '' && $c['merchant_sk'] !== '';
    }

    public static function sign(array $values, string $secret): string
    {
        return md5(implode('', array_map(static fn ($v): string => (string) $v, $values)) . $secret);
    }

    /**
     * Create a collection (top-up) order.
     * @return array{platform_osn:string,pay_url:string}
     */
    public static function payin(string $orderSn, int $amountKobo, string $name, string $email, string $phone, string $remark, string $redirectUrl): array
    {
        $c = self::requireConfig();
        $amount = Money::decimal($amountKobo);
        $data = self::post('/gateway/payin/', [
            'merchant_sn' => $c['merchant_sn'],
            'order_sn' => $orderSn,
            'amount' => $amount,
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'remark' => $remark,
            'redirect_url' => $redirectUrl,
            'sign' => self::sign([$c['merchant_ak'], $orderSn, $amount], $c['merchant_sk']),
        ]);
        $osn = (string) ($data['platform_osn'] ?? '');
        $url = (string) ($data['pay_url'] ?? '');
        if ($url === '' || !preg_match('#^https?://#i', $url)) throw new AfrstarException('AfrStar did not return a payment link.', true);
        return ['platform_osn' => $osn, 'pay_url' => $url];
    }

    /**
     * Create a bank payout.
     * @return array{platform_osn:string,status:string}
     */
    public static function payout(string $orderSn, int $amountKobo, string $accountName, string $accountNumber, string $bankCode, string $remark): array
    {
        $c = self::requireConfig();
        $amount = Money::decimal($amountKobo);
        $upi = '';
        $data = self::post('/gateway/payout/', [
            'merchant_sn' => $c['merchant_sn'],
            'order_sn' => $orderSn,
            'amount' => $amount,
            'type' => 'bank',
            'name' => $accountName,
            'account' => $accountNumber,
            'ifsc' => $bankCode,
            'upi_handle' => $upi,
            'remark' => $remark,
            'sign' => self::sign([$c['merchant_ak'], $orderSn, $accountNumber, $upi, $amount], $c['merchant_sk']),
        ]);
        return ['platform_osn' => (string) ($data['platform_osn'] ?? ''), 'status' => (string) ($data['status'] ?? '')];
    }

    /** Merchant balance in kobo. */
    public static function balance(int $timeout = 6): int
    {
        $c = self::requireConfig();
        $data = self::post('/gateway/balance/', [
            'merchant_sn' => $c['merchant_sn'],
            'sign' => self::sign([$c['merchant_ak']], $c['merchant_sk']),
        ], $timeout);
        return Money::toKobo((string) ($data['balance'] ?? '0'));
    }

    private static function requireConfig(): array
    {
        if (!self::isConfigured()) throw new AfrstarException('Payments are not available yet.', true);
        return self::config();
    }

    /**
     * POST JSON and return `data`. Throws AfrstarException; ->definitive is
     * true when AfrStar clearly answered "no" (so nothing was created), false
     * when the outcome is unknown (timeout, network or HTTP error).
     */
    private static function post(string $path, array $body, ?int $timeout = null): array
    {
        $c = self::config();
        if (!function_exists('curl_init')) throw new AfrstarException('The PHP curl extension is required for payments.', true);
        $ch = curl_init($c['base_url'] . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $timeout ?? $c['timeout'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($raw === false || $errno !== 0) {
            Logger::error('afrstar.network_error', ['path' => $path, 'curl_errno' => $errno]);
            // Could not connect at all: nothing reached AfrStar.
            throw new AfrstarException('AfrStar could not be reached. Please try again shortly.', $errno === CURLE_COULDNT_CONNECT || $errno === CURLE_COULDNT_RESOLVE_HOST);
        }
        $json = json_decode((string) $raw, true);
        if ($http < 200 || $http >= 300 || !is_array($json)) {
            Logger::error('afrstar.http_error', ['path' => $path, 'http' => $http]);
            throw new AfrstarException('AfrStar returned an unexpected response.', false);
        }
        if ((int) ($json['code'] ?? -1) !== 0) {
            $message = mb_substr((string) ($json['message'] ?? 'Request declined'), 0, 160);
            Logger::error('afrstar.api_error', ['path' => $path, 'code' => $json['code'] ?? null, 'message' => $message]);
            throw new AfrstarException('AfrStar declined the request: ' . $message, true);
        }
        return is_array($json['data'] ?? null) ? $json['data'] : [];
    }
}
