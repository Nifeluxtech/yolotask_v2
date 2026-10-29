<?php
declare(strict_types=1);

use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Security\AbuseGuard;
use YoloTask\Security\Csrf;
use YoloTask\Security\PasswordReset;

$role = yolotask_role();
if (!in_array($role, ['earner', 'advertiser', 'reviewer', 'admin'], true)) yolotask_error_page(404);
yolotask_private_headers();
$error = null;
$email = is_string($_POST['email'] ?? null) ? $_POST['email'] : '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify(is_string($_POST['_csrf'] ?? null) ? $_POST['_csrf'] : null)) {
        $error = 'Your form expired. Refresh the page and try again.';
    } elseif (($error = AbuseGuard::check('forgot', $_POST, 2)) === null) {
        if (!filter_var(trim($email), FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter a valid email address.';
        } else {
            try {
                PasswordReset::request(Database::connection(), $email, $role, yolotask_portal_url($role));
            } catch (Throwable $e) {
                Logger::error('auth.reset_request_failed', ['exception' => get_class($e)]);
            }
            yolotask_render_auth('done', [
                'doneHeading' => 'Check your email',
                'doneBody' => 'If a ' . strtolower(yolotask_role_title()) . ' account uses that address, a reset link is on its way. It expires in 30 minutes.',
                'doneLabel' => 'Back to sign in', 'doneUrl' => 'login.php',
            ]);
        }
    }
}
yolotask_render_auth('forgot', ['error' => $error, 'emailValue' => $email, 'formStamp' => AbuseGuard::formStamp('forgot')]);
