<?php
declare(strict_types=1);
namespace YoloTask\Payments;

use PDO;
use RuntimeException;
use YoloTask\Account\Account;
use YoloTask\Core\Settings;
use YoloTask\Money\Fees;
use YoloTask\Money\Money;
use YoloTask\Money\Wallet;
use YoloTask\Security\Audit;
use YoloTask\Security\Throttle;

/** Wallet top-ups through AfrStar collection orders. Crediting happens only in CallbackProcessor. */
final class PaymentOrders
{
    public static function limits(PDO $pdo): array
    {
        return ['min' => Settings::int($pdo, 'money.topup_min_kobo'), 'max' => Settings::int($pdo, 'money.topup_max_kobo')];
    }

    /**
     * Create an order and get AfrStar's hosted payment link.
     * @return array{order_sn:string,pay_url:string}
     */
    public static function create(PDO $pdo, array $user, int $amountKobo, string $phone, string $returnBaseUrl): array
    {
        if ((string) $user['role'] !== 'advertiser') throw new RuntimeException('Only advertiser accounts can top up.');
        if (empty($user['email_verified_at'])) throw new RuntimeException('Confirm your email before funding your wallet.');
        if (!AfrstarClient::isConfigured()) throw new RuntimeException('Payments are not available yet.');
        $limits = self::limits($pdo);
        if ($amountKobo < $limits['min']) throw new RuntimeException('The minimum top-up is ' . Money::format($limits['min']) . '.');
        if ($amountKobo > $limits['max']) throw new RuntimeException('The maximum top-up is ' . Money::format($limits['max']) . '.');
        $e164 = Account::normalisePhone($phone);
        if ($e164 === null) throw new RuntimeException('Enter your phone number for the payment page.');
        $userId = (int) $user['id'];
        if (Throttle::tooMany($pdo, 'topup', (string) $userId, 10, 3600)) throw new RuntimeException('Too many top-up attempts. Please try again in an hour.');
        Throttle::hit($pdo, 'topup', (string) $userId);
        if (empty($user['phone'])) {
            $pdo->prepare('UPDATE users SET phone = :p WHERE id = :id AND phone IS NULL')->execute(['p' => $e164, 'id' => $userId]);
        }
        $localPhone = str_starts_with($e164, '+234') ? '0' . substr($e164, 4) : ltrim($e164, '+');

        $wallet = Wallet::forUser($pdo, $userId);
        $orderSn = 'YTP' . gmdate('ymdHis') . strtoupper(bin2hex(random_bytes(5)));
        $pdo->prepare('INSERT INTO payment_orders (order_sn, user_id, wallet_id, amount_kobo, fee_kobo, status) VALUES (:sn, :u, :w, :a, :f, \'created\')')
            ->execute(['sn' => $orderSn, 'u' => $userId, 'w' => $wallet['id'], 'a' => $amountKobo, 'f' => Fees::topup($amountKobo)]);
        $orderId = (int) $pdo->lastInsertId();
        Audit::record($pdo, $userId, 'wallet.topup_started', 'payment_order', $orderId);

        try {
            $res = AfrstarClient::payin($orderSn, $amountKobo, mb_substr((string) $user['display_name'], 0, 80), (string) $user['email'], $localPhone,
                'YOLOTASK wallet top-up', rtrim($returnBaseUrl, '/') . '/topup-return.php?o=' . rawurlencode($orderSn));
        } catch (AfrstarException $e) {
            // Definitive refusal: nothing exists at AfrStar. Unknown outcome:
            // keep it pending so a late callback can still credit it.
            $pdo->prepare('UPDATE payment_orders SET status = :s, provider_message = :m WHERE id = :id')
                ->execute(['s' => $e->definitive ? 'failed' : 'pending', 'm' => mb_substr($e->getMessage(), 0, 255), 'id' => $orderId]);
            throw new RuntimeException('We could not start the payment. Please try again shortly.');
        }
        $pdo->prepare('UPDATE payment_orders SET status = \'pending\', platform_osn = :p, pay_url = :u WHERE id = :id AND status = \'created\'')
            ->execute(['p' => $res['platform_osn'] !== '' ? $res['platform_osn'] : null, 'u' => $res['pay_url'], 'id' => $orderId]);
        return ['order_sn' => $orderSn, 'pay_url' => $res['pay_url']];
    }

    /** One order owned by this user (null when it is not theirs). */
    public static function findForUser(PDO $pdo, int $userId, string $orderSn): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM payment_orders WHERE order_sn = :sn AND user_id = :u');
        $stmt->execute(['sn' => $orderSn, 'u' => $userId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public static function recentForUser(PDO $pdo, int $userId, int $limit = 10): array
    {
        $stmt = $pdo->prepare('SELECT order_sn, amount_kobo, credited_kobo, status, created_at, paid_at FROM payment_orders WHERE user_id = :u AND status <> \'created\' ORDER BY id DESC LIMIT ' . (int) $limit);
        $stmt->execute(['u' => $userId]);
        return $stmt->fetchAll() ?: [];
    }

    public static function list(PDO $pdo, string $status, string $q, int $page, int $perPage = 25): array
    {
        $where = [];
        $args = [];
        if (in_array($status, ['created', 'pending', 'paid', 'review', 'rejected', 'expired', 'failed'], true)) { $where[] = 'o.status = :s'; $args['s'] = $status; }
        if ($q !== '') { $where[] = '(o.order_sn = :q OR o.platform_osn = :q2 OR u.email LIKE :q3)'; $args['q'] = $q; $args['q2'] = $q; $args['q3'] = '%' . addcslashes($q, '%_\\') . '%'; }
        $sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $count = $pdo->prepare('SELECT COUNT(*) FROM payment_orders o JOIN users u ON u.id = o.user_id' . $sqlWhere);
        $count->execute($args);
        $total = (int) $count->fetchColumn();
        $page = max(1, $page);
        $stmt = $pdo->prepare('SELECT o.*, u.email, u.display_name FROM payment_orders o JOIN users u ON u.id = o.user_id' . $sqlWhere . ' ORDER BY o.id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage));
        $stmt->execute($args);
        return ['rows' => $stmt->fetchAll() ?: [], 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / $perPage))];
    }

    /** Cron: pending orders older than the expiry window become expired. No money moves. */
    public static function expireStale(PDO $pdo): int
    {
        $hours = max(1, Settings::int($pdo, 'money.payin_expiry_hours'));
        $stmt = $pdo->prepare("UPDATE payment_orders SET status = 'expired' WHERE status IN ('created', 'pending') AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :h HOUR)");
        $stmt->bindValue('h', $hours, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->rowCount();
    }
}
