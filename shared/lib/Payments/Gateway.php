<?php
declare(strict_types=1);
namespace YoloTask\Payments;

use PDO;
use YoloTask\Core\Settings;

/**
 * Which payment gateway handles new money movements (migration 0021).
 *
 * Two admin switches in Money settings:
 *   payments.gateway_payin  — top-ups and account activation fees
 *   payments.gateway_payout — earner withdrawals
 * Each is 'afrstar' or 'korapay'. The switch only decides where NEW orders
 * and NEW payouts go: every row stores the gateway it was created on
 * (`gateway` column), and callbacks/webhooks for older rows are still
 * accepted and settled on the gateway that row used.
 */
final class Gateway
{
    public const AFRSTAR = 'afrstar';
    public const KORAPAY = 'korapay';
    public const ALL = [self::AFRSTAR, self::KORAPAY];

    public static function label(string $gateway): string
    {
        return $gateway === self::KORAPAY ? 'Korapay' : 'AfrStar';
    }

    public static function payin(PDO $pdo): string
    {
        return self::clean(Settings::get($pdo, 'payments.gateway_payin', self::AFRSTAR));
    }

    public static function payout(PDO $pdo): string
    {
        return self::clean(Settings::get($pdo, 'payments.gateway_payout', self::AFRSTAR));
    }

    public static function isConfigured(string $gateway): bool
    {
        return $gateway === self::KORAPAY ? KorapayClient::isConfigured() : AfrstarClient::isConfigured();
    }

    public static function payinReady(PDO $pdo): bool
    {
        return self::isConfigured(self::payin($pdo));
    }

    public static function payoutReady(PDO $pdo): bool
    {
        return self::isConfigured(self::payout($pdo));
    }

    /** Ledger clearing account (money held at the provider) for a gateway. */
    public static function clearingAccount(string $gateway): string
    {
        return $gateway === self::KORAPAY ? \YoloTask\Money\Ledger::KORAPAY_CLEARING : \YoloTask\Money\Ledger::CLEARING;
    }

    public static function clean(?string $value): string
    {
        return in_array($value, self::ALL, true) ? (string) $value : self::AFRSTAR;
    }

    /**
     * Start a hosted payment on the given gateway (the one stored on the order row).
     * @return array{gateway:string,platform_osn:string,pay_url:string}
     * @throws AfrstarException|KorapayException (both carry ->definitive)
     */
    public static function startPayin(string $gateway, string $orderSn, int $amountKobo, string $name, string $email, string $localPhone, string $remark, string $redirectUrl): array
    {
        if ($gateway === self::KORAPAY) {
            $res = KorapayClient::initializeCharge($orderSn, $amountKobo, $name, $email, $remark, $redirectUrl);
            return ['gateway' => $gateway, 'platform_osn' => '', 'pay_url' => $res['checkout_url']];
        }
        $res = AfrstarClient::payin($orderSn, $amountKobo, $name, $email, $localPhone, $remark, $redirectUrl);
        return ['gateway' => $gateway, 'platform_osn' => $res['platform_osn'], 'pay_url' => $res['pay_url']];
    }
}
