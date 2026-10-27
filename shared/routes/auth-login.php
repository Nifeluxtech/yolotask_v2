<?php
declare(strict_types=1);

use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Security\Auth;
use YoloTask\Security\Csrf;

$role = defined('YOLOTASK_APP_ROLE') ? (string) YOLOTASK_APP_ROLE : '';
if (!in_array($role, ['earner', 'advertiser'], true)) { http_response_code(404); exit('Not found.'); }
$error = null;
$flash = is_string($_SESSION['_flash'] ?? null) ? $_SESSION['_flash'] : null;
unset($_SESSION['_flash']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify(is_string($_POST['_csrf'] ?? null) ? $_POST['_csrf'] : null)) {
        $error = 'Your form expired. Refresh the page and try again.';
    } else {
        $email = is_string($_POST['email'] ?? null) ? $_POST['email'] : '';
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        try {
            if (Auth::login(Database::connection(), $email, $password, $role)) {
                header('Location: index.php', true, 303);
                exit;
            }
            $error = 'Email or password is incorrect, or sign-in is temporarily unavailable.';
        } catch (Throwable $e) {
            Logger::error('auth.login_failed', ['exception' => get_class($e)]);
            $error = 'Sign-in is temporarily unavailable. Try again later.';
        }
    }
}
$csrfToken = Csrf::token();
$roleTitle = $role === 'earner' ? 'Earner' : 'Advertiser';
require YOLOTASK_APP_ROOT . '/apps/' . $role . '/pages/login.php';
