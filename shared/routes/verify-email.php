<?php
declare(strict_types=1);

use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Security\Auth;
use YoloTask\Security\Csrf;
use YoloTask\Security\EmailVerification;
use YoloTask\Security\Throttle;

$role = yolotask_role();
if (!in_array($role, Auth::registerableRoles(), true)) yolotask_error_page(404);
yolotask_private_headers();

try {
    $pdo = Database::connection();
} catch (Throwable $e) {
    Logger::error('verify.db_failed', ['exception' => get_class($e)]);
    yolotask_error_page(500);
}

// One-tap link from the email works whether or not this browser is signed in.
$token = is_string($_GET['t'] ?? null) ? $_GET['t'] : '';
if ($token !== '' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $verifiedId = EmailVerification::verifyToken($pdo, $token);
    $user = Auth::currentUser($pdo);
    if ($verifiedId !== null && is_array($user) && (int) $user['id'] === $verifiedId) {
        yolotask_flash('Email confirmed. Your account is fully active.');
        yolotask_redirect('dashboard.php');
    }
    if ($verifiedId !== null) {
        yolotask_render_auth('done', ['doneHeading' => 'Email confirmed', 'doneBody' => 'Thanks. Your email is confirmed and your account is fully active.', 'doneLabel' => 'Sign in', 'doneUrl' => 'login.php']);
    }
    yolotask_render_auth('done', ['doneHeading' => 'Link expired', 'doneBody' => 'That link has expired or was already used. Sign in to get a new code.', 'doneLabel' => 'Sign in', 'doneUrl' => 'login.php']);
}

$user = yolotask_require_user($pdo);
if (!empty($user['email_verified_at'])) yolotask_redirect('dashboard.php');
$userId = (int) $user['id'];
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify(is_string($_POST['_csrf'] ?? null) ? $_POST['_csrf'] : null)) {
        $error = 'Your form expired. Refresh the page and try again.';
    } elseif (($_POST['action'] ?? '') === 'resend') {
        if (EmailVerification::send($pdo, $user, yolotask_portal_url($role))) {
            yolotask_flash('A new code is on its way to ' . $user['email'] . '.');
        } else {
            yolotask_flash('Please wait a moment before requesting another code.', 'warning');
        }
        yolotask_redirect('verify.php');
    } else {
        $code = is_string($_POST['code'] ?? null) ? $_POST['code'] : '';
        if (EmailVerification::verifyCode($pdo, $userId, $code)) {
            yolotask_flash('Email confirmed. Your account is fully active.');
            yolotask_redirect('dashboard.php');
        }
        $error = 'That code is incorrect or has expired. Check the latest email, or send a new code.';
    }
}

$since = Throttle::secondsSinceLast($pdo, 'verify_send', (string) $userId);
$wait = $since === null ? 0 : max(0, EmailVerification::RESEND_COOLDOWN - $since);
yolotask_render_auth('verify', ['error' => $error, 'verifyEmail' => (string) $user['email'], 'resendWait' => $wait]);
