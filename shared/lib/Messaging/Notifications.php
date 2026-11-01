<?php
declare(strict_types=1);
namespace YoloTask\Messaging;

use PDO;
use YoloTask\Core\Logger;
use YoloTask\Core\Settings;

/**
 * In-app notifications (the bell inbox) plus the matching email, sent only
 * when the user left that category's email switched on. Security emails
 * (password, new sign-in, bank details) do not go through here: they are
 * always sent by their own code.
 *
 * notify() never throws: a failed notification must not undo the money or
 * decision it reports. A dedupe key makes a repeated event (double callback,
 * retried cron) add nothing and send nothing.
 */
final class Notifications
{
    public const PAGE_SIZE = 25;

    /** category => [label, description, roles, email allowed] */
    public const CATEGORIES = [
        'tasks' => ['Task results', 'Approved and rejected proofs. Approvals stay in your inbox; email is for rejections.', ['earner'], true],
        'appeals' => ['Appeal decisions', 'When an appeal you filed, or one on your campaign, is decided.', ['earner', 'advertiser'], true],
        'payments' => ['Top-ups and withdrawals', 'Wallet top-ups credited, withdrawals paid, failed or declined.', ['earner', 'advertiser'], true],
        'campaigns' => ['Campaign reviews', 'When your campaign is approved or not approved.', ['advertiser'], true],
        'rewards' => ['Referral earnings and bonuses', 'Referral milestone bonuses, deposit commissions and bonuses from YOLOTASK.', ['earner', 'advertiser'], true],
        'announcements' => ['Announcements', 'News from YOLOTASK. Shown in your inbox and as a banner; never emailed.', ['earner', 'advertiser'], false],
    ];

    /**
     * @param array{subject:string,heading:string,lines:list<string>,button?:?array,footnote?:?string}|null $email
     * @return bool true when a new inbox item was created
     */
    public static function notify(PDO $pdo, int $userId, string $category, string $title, string $body, ?string $url = null, ?string $dedupeKey = null, ?array $email = null): bool
    {
        try {
            if (!isset(self::CATEGORIES[$category])) throw new \InvalidArgumentException('Unknown notification category.');
            $user = $pdo->prepare('SELECT id, email, role, status FROM users WHERE id = :id');
            $user->execute(['id' => $userId]);
            $u = $user->fetch();
            if (!is_array($u) || (string) $u['status'] === 'closed') return false;
            $stmt = $pdo->prepare('INSERT IGNORE INTO notifications (user_id, category, title, body, url, dedupe_key) VALUES (:u, :c, :t, :b, :l, :k)');
            $stmt->execute(['u' => $userId, 'c' => $category, 't' => mb_substr($title, 0, 140), 'b' => mb_substr($body, 0, 500),
                'l' => $url !== null ? mb_substr($url, 0, 255) : null, 'k' => $dedupeKey !== null ? substr($dedupeKey, 0, 120) : null]);
            if ($stmt->rowCount() === 0) return false; // already notified
        } catch (\Throwable $e) {
            Logger::error('notifications.insert_failed', ['category' => $category, 'exception' => get_class($e), 'message' => $e->getMessage()]);
            return false;
        }
        if ($email !== null && self::CATEGORIES[$category][3] && self::emailEnabled($pdo, $userId, $category)) {
            try {
                Mailer::send((string) $u['email'], (string) $email['subject'], (string) $email['heading'], $email['lines'], $email['button'] ?? null, null, $email['footnote'] ?? null);
            } catch (\Throwable $e) {
                Logger::error('notifications.mail_failed', ['category' => $category, 'exception' => get_class($e)]);
            }
        }
        try {
            $abs = null;
            if ($url !== null && $url !== '' && function_exists('yolotask_portal_url')) {
                $abs = preg_match('#^https?://#', $url) ? $url : yolotask_portal_url((string) $u['role']) . '/' . ltrim($url, '/');
            }
            Push::send($userId, $title, $body, $abs);
        } catch (\Throwable $e) {
            Logger::error('notifications.push_failed', ['exception' => get_class($e)]);
        }
        return true;
    }

    /** Categories this role can receive. */
    public static function categoriesFor(string $role): array
    {
        return array_filter(self::CATEGORIES, static fn (array $c): bool => in_array($role, $c[2], true));
    }

    public static function emailEnabled(PDO $pdo, int $userId, string $category): bool
    {
        if (!(self::CATEGORIES[$category][3] ?? false)) return false;
        $stmt = $pdo->prepare('SELECT email_enabled FROM notification_preferences WHERE user_id = :u AND category = :c');
        $stmt->execute(['u' => $userId, 'c' => $category]);
        $v = $stmt->fetchColumn();
        return $v === false ? true : (bool) (int) $v;
    }

    /** @return array<string,bool> category => email on */
    public static function preferences(PDO $pdo, int $userId, string $role): array
    {
        $out = [];
        foreach (self::categoriesFor($role) as $key => $c) $out[$key] = $c[3];
        $stmt = $pdo->prepare('SELECT category, email_enabled FROM notification_preferences WHERE user_id = :u');
        $stmt->execute(['u' => $userId]);
        foreach ($stmt->fetchAll() ?: [] as $r) {
            if (isset($out[(string) $r['category']]) && self::CATEGORIES[(string) $r['category']][3]) $out[(string) $r['category']] = (bool) (int) $r['email_enabled'];
        }
        return $out;
    }

    /** @param list<string> $enabled categories whose email stays on */
    public static function savePreferences(PDO $pdo, int $userId, string $role, array $enabled): void
    {
        $stmt = $pdo->prepare('INSERT INTO notification_preferences (user_id, category, email_enabled) VALUES (:u, :c, :e) ON DUPLICATE KEY UPDATE email_enabled = VALUES(email_enabled)');
        foreach (self::categoriesFor($role) as $key => $c) {
            if (!$c[3]) continue;
            $stmt->execute(['u' => $userId, 'c' => $key, 'e' => in_array($key, $enabled, true) ? 1 : 0]);
        }
        \YoloTask\Security\Audit::record($pdo, $userId, 'account.notification_settings_updated', 'user', $userId);
    }

    public static function unreadCount(PDO $pdo, int $userId): int
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :u AND read_at IS NULL');
        $stmt->execute(['u' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    public static function list(PDO $pdo, int $userId, bool $unreadOnly, int $page): array
    {
        $where = 'WHERE user_id = :u' . ($unreadOnly ? ' AND read_at IS NULL' : '');
        return \YoloTask\Support\Pagination::query($pdo, "SELECT COUNT(*) FROM notifications {$where}",
            "SELECT id, category, title, body, url, read_at, created_at FROM notifications {$where} ORDER BY id DESC", ['u' => $userId], $page, self::PAGE_SIZE);
    }

    /** Mark one item read; returns its url (for "open") or null when it is not the user's. */
    public static function markRead(PDO $pdo, int $userId, int $id): ?string
    {
        $stmt = $pdo->prepare('SELECT url FROM notifications WHERE id = :id AND user_id = :u');
        $stmt->execute(['id' => $id, 'u' => $userId]);
        $url = $stmt->fetchColumn();
        if ($url === false) return null;
        $pdo->prepare('UPDATE notifications SET read_at = UTC_TIMESTAMP() WHERE id = :id AND user_id = :u AND read_at IS NULL')->execute(['id' => $id, 'u' => $userId]);
        return (string) ($url ?? '');
    }

    public static function markAllRead(PDO $pdo, int $userId): int
    {
        $stmt = $pdo->prepare('UPDATE notifications SET read_at = UTC_TIMESTAMP() WHERE user_id = :u AND read_at IS NULL');
        $stmt->execute(['u' => $userId]);
        return $stmt->rowCount();
    }

    /** Daily cron: delete read notifications older than notifications.retention_days (default 90). */
    public static function prune(PDO $pdo): int
    {
        $days = max(7, Settings::int($pdo, 'notifications.retention_days'));
        $stmt = $pdo->prepare('DELETE FROM notifications WHERE read_at IS NOT NULL AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :d DAY)');
        $stmt->bindValue('d', $days, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->rowCount();
    }

    /** Safe in-portal link for a stored url (relative paths only). */
    public static function safeUrl(?string $url): ?string
    {
        if ($url === null || $url === '') return null;
        return preg_match('#^[a-z0-9-]+\.php(\?[A-Za-z0-9=&%._\-]*)?(\#[A-Za-z0-9_-]*)?$#', $url) ? $url : null;
    }
}
