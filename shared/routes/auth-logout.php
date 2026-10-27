<?php
declare(strict_types=1);

use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Security\Auth;
use YoloTask\Security\Csrf;
use YoloTask\Security\Session;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); header('Allow: POST'); exit('Method not allowed.'); }
if (!Csrf::verify(is_string($_POST['_csrf'] ?? null) ? $_POST['_csrf'] : null)) { http_response_code(400); exit('Invalid form token.'); }
try {
    Auth::logout(Database::connection());
} catch (Throwable $e) {
    Logger::error('auth.logout_failed', ['exception' => get_class($e)]);
    Session::destroy();
}
header('Location: login.php', true, 303);
exit;
