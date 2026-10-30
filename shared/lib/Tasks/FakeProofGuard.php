<?php
declare(strict_types=1);
namespace YoloTask\Tasks;

use PDO;
use RuntimeException;

/**
 * Basic fake-proof checks: the exact same image (by hash of the uploaded
 * bytes) may not be used for a different submission, by anyone. Reusing a
 * screenshot from another task or another earner is the most common fake.
 */
final class FakeProofGuard
{
    public static function assertFresh(PDO $pdo, string $uploadSha256, int $submissionId = 0): void
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM submission_proofs WHERE upload_sha256 = :h AND submission_id <> :s');
        $stmt->execute(['h' => $uploadSha256, 's' => $submissionId]);
        if ((int) $stmt->fetchColumn() > 0) throw new RuntimeException('This screenshot has already been used for another task. Take a new screenshot of your own work.');
    }
}
