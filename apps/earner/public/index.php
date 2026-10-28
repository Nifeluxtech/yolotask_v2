<?php
declare(strict_types=1);

use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Security\Auth;
use YoloTask\Security\Csrf;

define('YOLOTASK_APP_ROLE', 'earner');
define('YOLOTASK_APP_ROOT', dirname(__DIR__, 3));
require_once YOLOTASK_APP_ROOT . '/shared/bootstrap.php';

$signedInUser = null;
$logoutCsrfToken = '';
if (isset($_SESSION['user_id'])) {
    try {
        $candidate = Auth::currentUser(Database::connection());
        if (is_array($candidate) && (string) ($candidate['role'] ?? '') === 'earner') {
            $signedInUser = $candidate;
            $logoutCsrfToken = Csrf::token();
        }
    } catch (Throwable $e) {
        Logger::error('auth.session_lookup_failed', ['exception' => get_class($e)]);
    }
}

$appName = (string) (yolotask_config()['app_name'] ?? 'YOLOTASK V2');
$roleTitle = 'Earner';
$roleSlug = 'earner';
$subtitle = 'Your time can take you further.';
$description = 'Create a YOLOTASK earner account and get ready for a more organized way to discover digital tasks. This staging build is focused on making account access reliable first.';
$heroChips = ['EARNER PORTAL', 'SECURE SIGN-IN', 'MOBILE READY'];
$statusMessage = $signedInUser !== null
    ? 'You are signed in. Task claiming, submissions, wallet balances, and withdrawals remain disabled in this staging build.'
    : 'Sign-up and sign-in are open for staging verification. Task claiming, submissions, wallet balances, and withdrawals are not enabled.';
$availabilityTitle = 'Earner staging access';
$availabilityBody = 'You can test account creation, validation, sign-in, session rotation, and sign-out here. Task listings and every wallet or payment action remain unavailable.';
$headerAction = ['url' => 'login.php', 'label' => 'Sign in'];
$primaryAction = ['url' => 'register.php', 'label' => 'Create an account'];
$secondaryAction = ['url' => 'login.php', 'label' => 'I already have an account'];
$navItems = [
    ['label' => 'Overview', 'url' => '#overview'],
    ['label' => 'Your benefits', 'url' => '#value'],
    ['label' => 'Access notes', 'url' => '#access'],
];
$features = [
    ['icon' => '01', 'tag' => 'ACCOUNT', 'title' => 'A private account space', 'description' => 'Your account is tied to the earner role, with password hashing, CSRF-protected forms, and secure session handling.'],
    ['icon' => '02', 'tag' => 'COMING NEXT', 'title' => 'A focused task experience', 'description' => 'Task discovery, task claims, and proof submissions will be introduced only after staging authentication checks are complete.'],
    ['icon' => '03', 'tag' => 'NO LIVE FUNDS', 'title' => 'Financial actions stay off', 'description' => 'Wallet balances, deposits, withdrawals, and payouts are not available in this test environment.'],
];
require YOLOTASK_APP_ROOT . '/shared/views/layouts/public.php';
