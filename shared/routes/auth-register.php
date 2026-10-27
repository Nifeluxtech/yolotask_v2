<?php
declare(strict_types=1);

use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Security\Auth;
use YoloTask\Security\Csrf;

$role = defined('YOLOTASK_APP_ROLE') ? (string) YOLOTASK_APP_ROLE : '';
if (!in_array($role, Auth::registerableRoles(), true)) { http_response_code(404); exit('Not found.'); }
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify(is_string($_POST['_csrf'] ?? null) ? $_POST['_csrf'] : null)) {
        $error = 'Your form expired. Refresh the page and try again.';
    } else {
        $name = is_string($_POST['display_name'] ?? null) ? $_POST['display_name'] : '';
        $email = is_string($_POST['email'] ?? null) ? $_POST['email'] : '';
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $confirmation = is_string($_POST['password_confirmation'] ?? null) ? $_POST['password_confirmation'] : '';
        $error = Auth::validateRegistration($name, $email, $password);
        if ($error === null && !hash_equals($password, $confirmation)) $error = 'The passwords do not match.';
        if ($error === null) {
            try {
                Auth::register(Database::connection(), $name, $email, $password, $role);
                $_SESSION['_flash'] = 'Account created. Sign in to continue.';
                header('Location: login.php', true, 303);
                exit;
            } catch (Throwable $e) {
                Logger::error('auth.registration_failed', ['exception' => get_class($e)]);
                $error = str_contains($e->getMessage(), 'account could not be created') ? $e->getMessage() : 'We could not create the account. Check the details and try again.';
            }
        }
    }
}
$csrfToken = Csrf::token();
$roleTitle = $role === 'earner' ? 'Earner' : 'Advertiser';
require YOLOTASK_APP_ROOT . '/apps/' . $role . '/pages/register.php';
