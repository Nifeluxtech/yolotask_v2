<?php
declare(strict_types=1);

use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Security\Auth;
use YoloTask\Security\Csrf;

$role = yolotask_role();
if (!in_array($role, ['earner', 'advertiser', 'reviewer', 'admin'], true)) yolotask_error_page(404);
yolotask_private_headers();
$error = null;
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_SESSION['user_id'])) {
    try {
        $existing = Auth::currentUser(Database::connection());
        if (is_array($existing) && (string) $existing['role'] === $role) yolotask_redirect('dashboard.php');
    } catch (Throwable $e) {
        Logger::error('auth.session_lookup_failed', ['exception' => get_class($e)]);
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify(is_string($_POST['_csrf'] ?? null) ? $_POST['_csrf'] : null)) {
        $error = 'Your form expired. Refresh the page and try again.';
    } else {
        $email = is_string($_POST['email'] ?? null) ? $_POST['email'] : '';
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        try {
            if (Auth::login(Database::connection(), $email, $password, $role)) yolotask_redirect('dashboard.php');
            $error = 'Email or password is incorrect, or sign-in is temporarily paused after several attempts.';
        } catch (Throwable $e) {
            Logger::error('auth.login_failed', ['exception' => get_class($e)]);
            $error = 'Sign-in is temporarily unavailable. Try again later.';
        }
    }
}
yolotask_render_auth('login', ['error' => $error, 'emailValue' => is_string($_POST['email'] ?? null) ? $_POST['email'] : '']);
