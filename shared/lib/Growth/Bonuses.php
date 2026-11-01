<?php
declare(strict_types=1);
namespace YoloTask\Growth;

use PDO;
use RuntimeException;
use YoloTask\Messaging\Notifications;
use YoloTask\Money\Ledger;
use YoloTask\Money\Money;
use YoloTask\Money\Wallet;
use YoloTask\Security\Audit;

/**
 * Admin one-off bonuses (Phase 6): Dr system:bonuses (expense) / Cr the
 * user's available balance, reason required, idempotent per form
 * (key bonus:<adminId>:<formKey>), audited, and announced to the user. Kept
 * apart from wallet adjustments so bonuses are reported as an expense and
 * never look like a correction.
 */
final class Bonuses
{
    public const ACCOUNT = 'system:bonuses';

    public static function grant(PDO $pdo, int $adminId, int $userId, int $amountKobo, string $reason, string $idemKey): int
    {
        $reason = trim($reason);
        if ($amountKobo <= 0) throw new RuntimeException('Enter an amount greater than zero.');
        if ($amountKobo > 10000000) throw new RuntimeException('Bonuses above ₦100,000 must be split and reviewed.');
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 200) throw new RuntimeException('A reason of 5 to 200 characters is required.');
        if (!preg_match('/^[a-f0-9]{32}$/', $idemKey)) throw new RuntimeException('Your form expired. Please try again.');
        $tx = self::pay($pdo, $adminId, $userId, $amountKobo, 'Bonus: ' . $reason, $idemKey, static function (PDO $pdo, array $tx, array $wallet) use ($adminId): void {
            Audit::record($pdo, $adminId, 'admin.bonus_granted', 'wallet', $wallet['id']);
        });
        if ($tx['created']) {
            Notifications::notify($pdo, $userId, 'rewards', 'Bonus: ' . Money::format($amountKobo), 'YOLOTASK added a ' . Money::format($amountKobo) . ' bonus to your wallet. ' . $reason,
                'wallet.php', 'bonus:' . $tx['id'], [
                    'subject' => 'You received a ' . Money::format($amountKobo) . ' bonus', 'heading' => 'Bonus added',
                    'lines' => ['YOLOTASK added a bonus of ' . Money::format($amountKobo) . ' to your wallet.', 'Note: ' . $reason],
                ]);
        }
        return $tx['id'];
    }

    /**
     * The shared bonus posting: checks the recipient (active earner or
     * advertiser), then in one transaction posts Dr system:bonuses / Cr the
     * wallet's available balance under key bonus:<adminId>:<idemKey> and, only
     * when the posting is new, runs $onCreated (audit, history rows) inside the
     * same transaction. Used by grant() and Leaderboard::reward().
     * @param callable(PDO, array{id:int,created:bool}, array):void|null $onCreated
     * @return array{id:int,created:bool}
     */
    public static function pay(PDO $pdo, int $adminId, int $userId, int $amountKobo, string $description, string $idemKey, ?callable $onCreated = null): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $idemKey)) throw new RuntimeException('Your form expired. Please try again.');
        $role = $pdo->prepare('SELECT role, status FROM users WHERE id = :id');
        $role->execute(['id' => $userId]);
        $u = $role->fetch();
        if (!is_array($u) || !in_array((string) $u['role'], ['earner', 'advertiser'], true)) throw new RuntimeException('Bonuses go to earner and advertiser wallets only.');
        if ((string) $u['status'] !== 'active') throw new RuntimeException('This account is not active.');
        Wallet::forUser($pdo, $userId);
        $pdo->beginTransaction();
        try {
            $wallet = Wallet::lock($pdo, $userId);
            $tx = Ledger::post($pdo, 'bonus:' . $adminId . ':' . $idemKey, 'bonus', mb_substr($description, 0, 255), [
                [Ledger::systemAccountId($pdo, self::ACCOUNT), 'debit', $amountKobo],
                [$wallet['available_account_id'], 'credit', $amountKobo],
            ], 'wallet', $wallet['id'], $adminId);
            if ($tx['created'] && $onCreated !== null) $onCreated($pdo, $tx, $wallet);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $tx;
    }
}
