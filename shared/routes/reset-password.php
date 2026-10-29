<?php
declare(strict_types=1);

use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Security\Csrf;
use YoloTask\Security\PasswordReset;
use YoloTask\Security\Session;

$role = yolotask_role();
if (!in_array($role, ['earner', 'advertiser', 'reviewer', 'admin'], true)) yolotask_error_page(404);
yolotask_private_headers();
$token = is_string($_POST['t'] ?? null) ? $_POST['t'] : (is_string($_GET['t'] ?? null) ? $_GET['t'] : '');

try {
    $pdo = Database::connection();
    $found = PasswordReset::find($pdo, $token, $role);
} catch (Throwable $e) {
    Logger::error('auth.reset_lookup_failed', ['exception' => get_class($e)]);
    yolotask_error_page(500);
}
if ($found === null) {
    yolotask_render_auth('done', ['doneHeading' => 'Link expired', 'doneBody' => 'That link has expired or was already used. Request a new one to continue.', 'doneLabel' => 'Request a new link', 'doneUrl' => 'forgot-password.php']);
}

$isInvite = $found['purpose'] === 'invite';
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $confirm = is_string($_POST['password_confirmation'] ?? null) ? $_POST['password_confirmation'] : '';
    if (!Csrf::verify(is_string($_POST['_csrf'] ?? null) ? $_POST['_csrf'] : null)) {
        $error = 'Your form expired. Refresh the page and try again.';
    } elseif (strlen($password) < 12 || strlen($password) > 72) {
        $error = 'Use a password of at least 12 characters and no more than 72 bytes.';
    } elseif (!hash_equals($password, $confirm)) {
        $error = 'The passwords do not match.';
    } else {
        try {
            PasswordReset::complete($pdo, $found, $password);
            Session::destroy();
            session_start();
            yolotask_flash($isInvite ? 'Your account is active. Sign in with your new password.' : 'Password updated. Sign in with your new password.');
            yolotask_redirect('login.php');
        } catch (Throwable $e) {
            Logger::error('auth.reset_failed', ['exception' => get_class($e)]);
            $error = 'We could not update the password. Try again.';
        }
    }
}
yolotask_render_auth('reset', ['error' => $error, 'resetToken' => $token, 'isInvite' => $isInvite, 'emailValue' => (string) $found['email']]);
