<?php
declare(strict_types=1);
namespace YoloTask\Payments;

use PDO;
use RuntimeException;
use YoloTask\Messaging\Mailer;
use YoloTask\Security\Audit;
use YoloTask\Security\Throttle;

/** The single payout bank account an earner keeps on file. */
final class BankAccounts
{
    public static function get(PDO $pdo, int $userId): ?array
    {
        $stmt = $pdo->prepare('SELECT bank_code, bank_name, account_number, account_name, name_verified_via, updated_at FROM bank_accounts WHERE user_id = :u');
        $stmt->execute(['u' => $userId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /** Save or replace the bank account. Always needs the account password; emails a security alert. */
    public static function save(PDO $pdo, array $user, string $bankCode, string $accountNumber, string $accountName, string $password): void
    {
        $userId = (int) $user['id'];
        if ((string) $user['role'] !== 'earner') throw new RuntimeException('Only earner accounts add payout bank accounts.');
        $bankCode = trim($bankCode);
        $accountNumber = preg_replace('/\D/', '', $accountNumber) ?? '';
        $accountName = trim((string) preg_replace('/\s+/', ' ', $accountName));
        if (!BankCodes::isValid($bankCode)) throw new RuntimeException('Choose your bank from the list.');
        if (!preg_match('/^\d{10}$/', $accountNumber)) throw new RuntimeException('Enter your 10-digit NUBAN account number.');
        $len = mb_strlen($accountName);
        if ($len < 3 || $len > 100 || !preg_match("/^[\\p{L} .,'&-]+$/u", $accountName)) throw new RuntimeException('Enter the account name exactly as your bank shows it.');
        if (Throttle::tooMany($pdo, 'bank_account', (string) $userId, 5, 3600)) throw new RuntimeException('Too many changes. Please try again in an hour.');
        Throttle::hit($pdo, 'bank_account', (string) $userId);

        $stmt = $pdo->prepare('SELECT email, password_hash FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();
        if (!is_array($row) || !password_verify($password, (string) $row['password_hash'])) throw new RuntimeException('Your password is incorrect.');

        $verifiedVia = 'none';
        if (PaystackClient::isConfigured()) {
            $resolved = PaystackClient::resolveAccountName($accountNumber, $bankCode);
            if ($resolved !== null) { $accountName = mb_substr($resolved, 0, 100); $verifiedVia = 'paystack'; }
        }

        $existing = self::get($pdo, $userId);
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO bank_accounts (user_id, bank_code, bank_name, account_number, account_name, name_verified_via) VALUES (:u, :c, :n, :a, :m, :v)
                ON DUPLICATE KEY UPDATE bank_code = VALUES(bank_code), bank_name = VALUES(bank_name), account_number = VALUES(account_number), account_name = VALUES(account_name), name_verified_via = VALUES(name_verified_via)')
                ->execute(['u' => $userId, 'c' => $bankCode, 'n' => (string) BankCodes::name($bankCode), 'a' => $accountNumber, 'm' => $accountName, 'v' => $verifiedVia]);
            Audit::record($pdo, $userId, $existing === null ? 'wallet.bank_account_added' : 'wallet.bank_account_changed', 'user', $userId);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        $when = (new \DateTimeImmutable('now', new \DateTimeZone('Africa/Lagos')))->format('j M Y, g:i A') . ' WAT';
        Mailer::send((string) $row['email'], $existing === null ? 'A payout bank account was added to YOLOTASK' : 'Your YOLOTASK payout bank account was changed', 'Payout bank account ' . ($existing === null ? 'added' : 'changed'),
            ['Withdrawals from your YOLOTASK wallet will now go to ' . BankCodes::name($bankCode) . ' ' . CallbackProcessor::mask($accountNumber) . ' (' . $accountName . ').', 'This change was made on ' . $when . '.'],
            null, null, "If this wasn't you, reset your password straight away and contact YOLOTASK support before requesting a withdrawal.");
    }
}
