<?php
declare(strict_types=1);

use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Jobs\JobProofs;
use YoloTask\Security\Auth;

/*
 * Job proof endpoint (earner, advertiser, reviewer and admin portals; migration 0023),
 * the Jobs twin of proof.php. Files live in runtime/job-proofs outside every
 * document root; this is the only way to read one. Access: the earner who
 * uploaded it, the advertiser who owns the job, any admin, and reviewers once
 * the attempt is appealed or when it is on a YOLOTASK job (migration 0024). Everyone else gets the same 404, so ids reveal nothing.
 * Images are shown inline; every other file is a sandboxed download.
 */
if (!in_array(yolotask_role(), ['earner', 'advertiser', 'reviewer', 'admin'], true)) yolotask_error_page(404);
try {
    $pdo = Database::connection();
    $user = Auth::currentUser($pdo);
} catch (Throwable $e) {
    Logger::error('job_proof.load_failed', ['exception' => get_class($e)]);
    http_response_code(500);
    exit;
}
$deny = static function (int $code = 404): never {
    http_response_code($code);
    header('Cache-Control: no-store, private');
    header('Content-Type: text/plain; charset=utf-8');
    exit($code === 403 ? 'Access denied.' : 'Not found.');
};
if (!is_array($user) || (string) $user['role'] !== yolotask_role()) $deny(403);
$file = JobProofs::find($pdo, (int) ($_GET['id'] ?? 0));
if ($file === null || !JobProofs::canView($file, $user)) $deny();
$path = JobProofs::path($file);
if ($path === null) {
    Logger::error('job_proof.file_missing', ['file_id' => (int) $file['id']]);
    $deny();
}
$isImage = (string) $file['kind'] === 'image' && in_array((string) $file['mime'], ['image/jpeg', 'image/png', 'image/webp'], true);
header('Content-Type: ' . ($isImage ? (string) $file['mime'] : 'application/octet-stream'));
header('Content-Length: ' . (string) filesize($path));
$name = $isImage ? 'job-proof-' . (int) $file['id'] . '.' . pathinfo($path, PATHINFO_EXTENSION) : str_replace('"', '', JobProofs::cleanName((string) ($file['original_name'] ?? 'file')));
header('Content-Disposition: ' . ($isImage ? 'inline' : 'attachment') . '; filename="' . $name . '"');
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: SAMEORIGIN');
while (ob_get_level() > 0) ob_end_clean();
readfile($path);
exit;
