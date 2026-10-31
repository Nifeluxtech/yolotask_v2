<?php
declare(strict_types=1);
namespace YoloTask\Tasks;

use PDO;
use RuntimeException;
use YoloTask\Core\Logger;

/**
 * Proof files: stored in the private runtime directory (never under a public
 * document root) with random names, recorded with their hashes, and served
 * only through proof.php after an ownership check: the earner who uploaded
 * it, the advertiser who owns the campaign, an admin, or a reviewer when the
 * submission is appealed or still pending (reviewer queues).
 */
final class Proofs
{
    /** Private runtime root (see Support\Storage). */
    public static function root(): string
    {
        return \YoloTask\Support\Storage::root();
    }

    public static function dir(): string
    {
        return self::root() . '/proofs';
    }

    /**
     * Write cleaned image bytes and record them (inside the caller's transaction).
     * @param array{bytes:string,mime:string,ext:string,width:int,height:int,upload_sha256:string} $img
     */
    public static function store(PDO $pdo, int $submissionId, int $attempt, array $img, bool $appeal = false): int
    {
        $sub = gmdate('Y/m');
        $dir = self::dir() . '/' . $sub;
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            Logger::error('proofs.mkdir_failed', ['dir' => $sub]);
            throw new RuntimeException('Proof uploads are not available right now. Please try again later.');
        }
        $rel = $sub . '/' . bin2hex(random_bytes(16)) . '.' . $img['ext'];
        $path = self::dir() . '/' . $rel;
        if (@file_put_contents($path, $img['bytes'], LOCK_EX) !== strlen($img['bytes'])) {
            Logger::error('proofs.write_failed', ['rel' => $rel]);
            throw new RuntimeException('Proof uploads are not available right now. Please try again later.');
        }
        @chmod($path, 0600);
        self::$written[] = $path;
        $pdo->prepare('INSERT INTO submission_proofs (submission_id, attempt, is_appeal, storage_path, mime, bytes, width, height, upload_sha256, stored_sha256) VALUES (:s, :a, :ia, :p, :m, :b, :w, :h, :u, :st)')
            ->execute(['s' => $submissionId, 'a' => $attempt, 'ia' => $appeal ? 1 : 0, 'p' => $rel, 'm' => $img['mime'], 'b' => strlen($img['bytes']), 'w' => $img['width'], 'h' => $img['height'], 'u' => $img['upload_sha256'], 'st' => hash('sha256', $img['bytes'])]);
        return (int) $pdo->lastInsertId();
    }

    /** @var list<string> files written in this request, removed again if the transaction fails */
    private static array $written = [];

    public static function discardWritten(): void
    {
        foreach (self::$written as $f) @unlink($f);
        self::$written = [];
    }

    public static function forgetWritten(): void
    {
        self::$written = [];
    }

    public static function forSubmission(PDO $pdo, int $submissionId): array
    {
        $stmt = $pdo->prepare('SELECT id, attempt, is_appeal, mime, bytes, width, height, created_at FROM submission_proofs WHERE submission_id = :s ORDER BY attempt DESC, id DESC');
        $stmt->execute(['s' => $submissionId]);
        return $stmt->fetchAll() ?: [];
    }

    /** Proof row with the owners needed for the access check. */
    public static function find(PDO $pdo, int $proofId): ?array
    {
        $stmt = $pdo->prepare('SELECT p.id, p.storage_path, p.mime, p.bytes, s.id submission_id, s.status submission_status, s.earner_user_id, c.advertiser_user_id,
            (SELECT COUNT(*) FROM submission_appeals a WHERE a.submission_id = s.id) appeal_count FROM submission_proofs p
            JOIN submissions s ON s.id = p.submission_id JOIN campaigns c ON c.id = s.campaign_id WHERE p.id = :id');
        $stmt->execute(['id' => $proofId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public static function canView(array $proof, array $user): bool
    {
        return match ((string) $user['role']) {
            'admin' => true,
            'earner' => (int) $proof['earner_user_id'] === (int) $user['id'],
            'advertiser' => (int) $proof['advertiser_user_id'] === (int) $user['id'],
            // Reviewers see proofs only for work in their queues: appealed or still pending (stale queue).
            'reviewer' => (int) ($proof['appeal_count'] ?? 0) > 0 || (string) ($proof['submission_status'] ?? '') === 'pending',
            default => false,
        };
    }

    /** Absolute path of a stored proof, refusing anything outside the proofs directory. */
    public static function path(array $proof): ?string
    {
        $rel = (string) $proof['storage_path'];
        if (!preg_match('#^\d{4}/\d{2}/[a-f0-9]{32}\.(?:jpg|png|webp)$#', $rel)) return null;
        $path = self::dir() . '/' . $rel;
        return is_file($path) ? $path : null;
    }
}
