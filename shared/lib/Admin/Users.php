<?php
declare(strict_types=1);
namespace YoloTask\Admin;

use PDO;
use RuntimeException;
use YoloTask\Messaging\Mailer;
use YoloTask\Security\Audit;
use YoloTask\Security\PasswordReset;

/** Admin user directory: search, suspend/restore and staff invitations. */
final class Users
{
    public const PAGE_SIZE = 25;

    public static function search(PDO $pdo, string $q, string $role, string $status, int $page): array
    {
        $where = [];
        $args = [];
        if ($q !== '') { $where[] = '(email LIKE :q OR display_name LIKE :q2 OR phone LIKE :q3)'; $like = '%' . addcslashes($q, '%_\\') . '%'; $args['q'] = $like; $args['q2'] = $like; $args['q3'] = $like; }
        if (in_array($role, ['earner', 'advertiser', 'reviewer', 'admin'], true)) { $where[] = 'role = :role'; $args['role'] = $role; }
        if (in_array($status, ['active', 'suspended', 'closed'], true)) { $where[] = 'status = :status'; $args['status'] = $status; }
        $sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $count = $pdo->prepare('SELECT COUNT(*) FROM users' . $sqlWhere);
        $count->execute($args);
        $total = (int) $count->fetchColumn();
        $page = max(1, $page);
        $stmt = $pdo->prepare('SELECT id, display_name, email, phone, role, status, email_verified_at, last_login_at, created_at FROM users' . $sqlWhere . ' ORDER BY id DESC LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . (($page - 1) * self::PAGE_SIZE));
        $stmt->execute($args);
        return ['rows' => $stmt->fetchAll() ?: [], 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / self::PAGE_SIZE))];
    }

    public static function counts(PDO $pdo): array
    {
        $out = ['earner' => 0, 'advertiser' => 0, 'reviewer' => 0, 'admin' => 0, 'suspended' => 0, 'unverified' => 0, 'new_7d' => 0];
        foreach ($pdo->query("SELECT role, COUNT(*) c FROM users WHERE status <> 'closed' GROUP BY role") as $r) $out[(string) $r['role']] = (int) $r['c'];
        $out['suspended'] = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'suspended'")->fetchColumn();
        $out['unverified'] = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'active' AND email_verified_at IS NULL")->fetchColumn();
        $out['new_7d'] = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)')->fetchColumn();
        return $out;
    }

    public static function setStatus(PDO $pdo, int $adminId, int $targetId, string $status): void
    {
        if (!in_array($status, ['active', 'suspended'], true)) throw new RuntimeException('Unknown status.');
        if ($adminId === $targetId) throw new RuntimeException('You cannot change your own account status.');
        $stmt = $pdo->prepare('SELECT role, status FROM users WHERE id = :id');
        $stmt->execute(['id' => $targetId]);
        $target = $stmt->fetch();
        if (!is_array($target)) throw new RuntimeException('That account no longer exists.');
        if ((string) $target['status'] === 'closed') throw new RuntimeException('Closed accounts cannot be changed.');
        if ((string) $target['role'] === 'admin' && $status === 'suspended') {
            $admins = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'")->fetchColumn();
            if ($admins <= 1) throw new RuntimeException('At least one active admin must remain.');
        }
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE users SET status = :s WHERE id = :id')->execute(['s' => $status, 'id' => $targetId]);
            if ($status === 'suspended') {
                $pdo->prepare('UPDATE user_sessions SET revoked_at = UTC_TIMESTAMP() WHERE user_id = :u AND revoked_at IS NULL')->execute(['u' => $targetId]);
            }
            Audit::record($pdo, $adminId, $status === 'suspended' ? 'admin.user_suspended' : 'admin.user_restored', 'user', $targetId);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /** Create a reviewer or admin and email a 48-hour link to set their password. */
    public static function inviteStaff(PDO $pdo, int $adminId, string $name, string $email, string $role): int
    {
        $name = trim($name);
        $email = strtolower(trim($email));
        if (!in_array($role, ['reviewer', 'admin'], true)) throw new RuntimeException('Staff role must be reviewer or admin.');
        if ($name === '' || strlen($name) > 80) throw new RuntimeException('Enter a name of 1 to 80 characters.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid email address.');
        $exists = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = :e');
        $exists->execute(['e' => $email]);
        if ((int) $exists->fetchColumn() > 0) throw new RuntimeException('An account already uses that email address.');

        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO users (display_name, email, password_hash, role, status, created_by_user_id) VALUES (:n, :e, :h, :r, \'active\', :by)')
                ->execute(['n' => $name, 'e' => $email, 'h' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), 'r' => $role, 'by' => $adminId ?: null]);
            $userId = (int) $pdo->lastInsertId();
            $token = PasswordReset::issue($pdo, $userId, 'invite', 172800);
            Audit::record($pdo, $adminId ?: null, 'admin.staff_invited', 'user', $userId);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        $portal = function_exists('yolotask_portal_url') ? yolotask_portal_url($role) : 'https://' . $role . '.yolotask.com.ng';
        $first = trim((string) strtok($name, ' '));
        Mailer::send($email, 'You have been invited to YOLOTASK ' . ucfirst($role), 'Set up your staff account',
            ["Hi {$first}, you've been added to the YOLOTASK " . ($role === 'admin' ? 'admin' : 'reviewer') . ' team. Choose a password to activate your account. This link works once and expires in 48 hours.'],
            ['label' => 'Set my password', 'url' => rtrim($portal, '/') . '/reset-password.php?t=' . $token],
            null, "If you weren't expecting this, you can ignore this email.");
        return $userId;
    }
}
