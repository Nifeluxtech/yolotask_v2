<?php
declare(strict_types=1);
namespace YoloTask\Jobs;

use PDO;
use RuntimeException;
use YoloTask\Core\Logger;
use YoloTask\Core\Settings;
use YoloTask\Support\Storage;
use YoloTask\Support\Uploads;

/**
 * Job proof files (migration 0023), stored like task proofs: private runtime
 * storage (runtime/job-proofs/Y/m, never under a document root), random
 * names, 0600, hashes recorded, served only by job-proof.php after an access
 * check: the earner who uploaded it, the advertiser who owns the job, any
 * admin, and reviewers once the attempt is appealed.
 *
 * Images (screenshot / image proof) go through Support\Uploads::image with
 * tasks.proof_max_bytes, exactly like task proofs (type from the bytes,
 * re-encoded, metadata stripped) and the same "reused screenshot" check.
 * Files (file proof) are checked by extension and size (FILE_MAX_BYTES) and
 * always served as a download (application/octet-stream, sandboxed).
 */
final class JobProofs
{
    public const FILE_MAX_BYTES = 10485760; // 10 MB (the host's upload_max_filesize may be lower)
    public const FILE_EXTENSIONS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'zip', 'jpg', 'jpeg', 'png', 'webp', 'mp3', 'm4a', 'wav', 'mp4', 'mov'];

    /** @var list<string> files written in this request, removed again if the transaction fails */
    private static array $written = [];

    public static function hasUpload(mixed $file): bool
    {
        return is_array($file) && isset($file['error']) && !is_array($file['error']) && (int) $file['error'] !== UPLOAD_ERR_NO_FILE;
    }

    /** @return array{bytes:string,mime:string,ext:string,name:?string,sha:string} */
    public static function image(PDO $pdo, mixed $file, int $attemptId): array
    {
        $img = Uploads::image($file, Settings::int($pdo, 'tasks.proof_max_bytes'));
        self::assertFresh($pdo, $img['upload_sha256'], $attemptId);
        return ['bytes' => $img['bytes'], 'mime' => $img['mime'], 'ext' => $img['ext'], 'name' => null, 'sha' => $img['upload_sha256']];
    }

    /** @return array{bytes:string,mime:string,ext:string,name:?string,sha:string} */
    public static function file(mixed $file): array
    {
        if (!is_array($file) || !isset($file['error'], $file['tmp_name']) || is_array($file['error'])) throw new RuntimeException('Attach the file for this step.');
        $err = (int) $file['error'];
        if ($err === UPLOAD_ERR_NO_FILE) throw new RuntimeException('Attach the file for this step.');
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) throw new RuntimeException(self::tooBig());
        if ($err !== UPLOAD_ERR_OK) throw new RuntimeException('The upload did not finish. Please try again.');
        $tmp = (string) $file['tmp_name'];
        if (PHP_SAPI !== 'cli' && !is_uploaded_file($tmp)) throw new RuntimeException('The upload did not finish. Please try again.');
        $raw = (string) @file_get_contents($tmp);
        return self::fileFromBytes($raw, (string) ($file['name'] ?? 'file'));
    }

    /** Same checks for bytes already in memory (used by file() and tests). */
    public static function fileFromBytes(string $raw, string $name): array
    {
        if ($raw === '') throw new RuntimeException('The file is empty.');
        if (strlen($raw) > self::FILE_MAX_BYTES) throw new RuntimeException(self::tooBig());
        $name = self::cleanName($name);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, self::FILE_EXTENSIONS, true)) throw new RuntimeException('Upload a ' . implode(', ', self::FILE_EXTENSIONS) . ' file.');
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($raw);
        if (preg_match('#^(text/html|application/x-(php|httpd-php|sh|executable|dosexec|msdownload|elf))#', $mime)) throw new RuntimeException('That file type is not allowed.');
        return ['bytes' => $raw, 'mime' => mb_substr($mime !== '' ? $mime : 'application/octet-stream', 0, 100), 'ext' => $ext, 'name' => $name, 'sha' => hash('sha256', $raw)];
    }

    /** The same screenshot may not prove two different jobs, or a job and a task. */
    public static function assertFresh(PDO $pdo, string $sha, int $attemptId): void
    {
        $stmt = $pdo->prepare("SELECT (SELECT COUNT(*) FROM job_files WHERE upload_sha256 = :h AND kind = 'image' AND attempt_id <> :a) + (SELECT COUNT(*) FROM submission_proofs WHERE upload_sha256 = :h2)");
        $stmt->execute(['h' => $sha, 'a' => $attemptId, 'h2' => $sha]);
        if ((int) $stmt->fetchColumn() > 0) throw new RuntimeException('This screenshot has already been used for another task or job. Take a new screenshot of your own work.');
    }

    /** Write the bytes and record them (inside the caller's transaction). Returns the job_files id. */
    public static function store(PDO $pdo, int $attemptId, int $stepId, string $kind, array $f): int
    {
        $sub = gmdate('Y/m');
        $dir = Storage::dir('job-proofs/' . $sub);
        if ($dir === null) {
            Logger::error('job_proofs.mkdir_failed', ['dir' => $sub]);
            throw new RuntimeException('Proof uploads are not available right now. Please try again later.');
        }
        $rel = $sub . '/' . bin2hex(random_bytes(16)) . '.' . $f['ext'];
        $path = $dir . '/' . basename($rel);
        if (@file_put_contents($path, $f['bytes'], LOCK_EX) !== strlen($f['bytes'])) {
            Logger::error('job_proofs.write_failed', ['rel' => $rel]);
            throw new RuntimeException('Proof uploads are not available right now. Please try again later.');
        }
        @chmod($path, 0600);
        self::$written[] = $path;
        $pdo->prepare('INSERT INTO job_files (attempt_id, step_id, kind, storage_path, mime, original_name, bytes, upload_sha256) VALUES (:a, :s, :k, :p, :m, :n, :b, :h)')
            ->execute(['a' => $attemptId, 's' => $stepId, 'k' => $kind, 'p' => $rel, 'm' => $f['mime'], 'n' => $f['name'], 'b' => strlen($f['bytes']), 'h' => $f['sha']]);
        return (int) $pdo->lastInsertId();
    }

    public static function discardWritten(): void
    {
        foreach (self::$written as $f) @unlink($f);
        self::$written = [];
    }

    public static function forgetWritten(): void
    {
        self::$written = [];
    }

    /** File row with the owners needed for the access check. */
    public static function find(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT f.*, a.earner_user_id, a.advertiser_user_id, a.status attempt_status, (SELECT COUNT(*) FROM job_appeals ap WHERE ap.attempt_id = a.id) appeal_count
            FROM job_files f JOIN job_attempts a ON a.id = f.attempt_id WHERE f.id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public static function canView(array $file, array $user): bool
    {
        return match ((string) $user['role']) {
            'admin' => true,
            'earner' => (int) $file['earner_user_id'] === (int) $user['id'],
            'advertiser' => (int) $file['advertiser_user_id'] === (int) $user['id'],
            'reviewer' => (int) ($file['appeal_count'] ?? 0) > 0, // reviewers see job proofs only for appealed attempts
            default => false,
        };
    }

    /** Absolute path of a stored file, refusing anything outside runtime/job-proofs. */
    public static function path(array $file): ?string
    {
        $rel = (string) $file['storage_path'];
        if (!preg_match('#^\d{4}/\d{2}/[a-f0-9]{32}\.[a-z0-9]{2,5}$#', $rel)) return null;
        $path = Storage::root() . '/job-proofs/' . $rel;
        return is_file($path) ? $path : null;
    }

    public static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[^A-Za-z0-9._ \-]/', '_', $name);
        $name = trim($name, ' .');
        return mb_substr($name !== '' ? $name : 'file', 0, 150);
    }

    private static function tooBig(): string
    {
        return 'The file is larger than ' . (self::FILE_MAX_BYTES / 1048576) . ' MB.';
    }
}
