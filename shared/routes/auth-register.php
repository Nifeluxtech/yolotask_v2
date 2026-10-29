<?php
declare(strict_types=1);

use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Security\AbuseGuard;
use YoloTask\Security\Auth;
use YoloTask\Security\Crypto;
use YoloTask\Security\Csrf;
use YoloTask\Security\EmailVerification;
use YoloTask\Security\Throttle;

$role = yolotask_role();
if (!in_array($role, Auth::registerableRoles(), true)) yolotask_error_page(404);
yolotask_private_headers();
$error = null;
$name = is_string($_POST['display_name'] ?? null) ? $_POST['display_name'] : '';
$email = is_string($_POST['email'] ?? null) ? $_POST['email'] : '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify(is_string($_POST['_csrf'] ?? null) ? $_POST['_csrf'] : null)) {
        $error = 'Your form expired. Refresh the page and try again.';
    } else {
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $confirmation = is_string($_POST['password_confirmation'] ?? null) ? $_POST['password_confirmation'] : '';
        $error = AbuseGuard::check('register', $_POST)
            ?? Auth::validateRegistration($name, $email, $password);
        if ($error === null && !hash_equals($password, $confirmation)) $error = 'The passwords do not match.';
        if ($error === null && AbuseGuard::isDisposableEmail($email)) $error = 'Please use a permanent email address. Temporary inboxes are not accepted.';
        if ($error === null) {
            try {
                $pdo = Database::connection();
                if (Throttle::tooMany($pdo, 'register_ip', Crypto::clientIp(), 5, 3600)) {
                    $error = 'Too many accounts were created from this connection. Try again in an hour.';
                } else {
                    Throttle::hit($pdo, 'register_ip', Crypto::clientIp());
                    $userId = Auth::register($pdo, $name, $email, $password, $role);
                    Auth::signInNewAccount($pdo, $userId);
                    $sent = EmailVerification::send($pdo, ['id' => $userId, 'display_name' => trim($name), 'email' => strtolower(trim($email))], yolotask_portal_url($role));
                    yolotask_flash($sent ? 'Account created. We sent a code to your email.' : 'Account created. Request a new code below to confirm your email.', $sent ? 'success' : 'warning');
                    yolotask_redirect('verify.php');
                }
            } catch (Throwable $e) {
                Logger::error('auth.registration_failed', ['exception' => get_class($e)]);
                $error = str_contains($e->getMessage(), 'account could not be created') ? $e->getMessage() : 'We could not create the account. Check the details and try again.';
            }
        }
    }
}
yolotask_render_auth('register', ['error' => $error, 'nameValue' => $name, 'emailValue' => $email, 'formStamp' => AbuseGuard::formStamp('register')]);
