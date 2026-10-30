<?php
declare(strict_types=1);

use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Security\Auth;
use YoloTask\Tasks\Proofs;

/*
 * Proof image endpoint (earner, advertiser and admin portals). Files live
 * outside every document root; this is the only way to read one. Access:
 * the earner who uploaded it, the advertiser who owns the campaign, any
 * admin. Everyone else gets the same 404, so ids reveal nothing.
 */
if (!in_array(yolotask_role(), ['earner', 'advertiser', 'admin'], true)) yolotask_error_page(404);
try {
    $pdo = Database::connection();
    $user = Auth::currentUser($pdo);
} catch (Throwable $e) {
    Logger::error('proof.load_failed', ['exception' => get_class($e)]);
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
$proof = Proofs::find($pdo, (int) ($_GET['id'] ?? 0));
if ($proof === null || !Proofs::canView($proof, $user)) $deny();
$path = Proofs::path($proof);
if ($path === null) {
    Logger::error('proof.file_missing', ['proof_id' => (int) $proof['id']]);
    $deny();
}
$mime = in_array((string) $proof['mime'], ['image/jpeg', 'image/png', 'image/webp'], true) ? (string) $proof['mime'] : 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: inline; filename="proof-' . (int) $proof['id'] . '.' . pathinfo($path, PATHINFO_EXTENSION) . '"');
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: SAMEORIGIN');
readfile($path);
exit;
