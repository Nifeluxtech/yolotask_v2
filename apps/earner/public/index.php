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
$dashboardUrl = $signedInUser !== null ? 'dashboard.php' : null;

$appName = (string) (yolotask_config()['app_name'] ?? 'YOLOTASK');
$legalBaseUrl = yolotask_portal_url('main');
$roleTitle = 'Earner';
$roleSlug = 'earner';
$subtitle = 'Turn spare minutes into real earnings.';
$description = 'Complete simple digital tasks for brands and businesses, submit your proof, and get paid once it is approved. No investment, no deposits — just verified work.';
$heroChips = ['Verified tasks', 'Fast reviews', 'Mobile first'];
$headerAction = ['url' => 'login.php', 'label' => 'Sign in'];
$primaryAction = ['url' => 'register.php', 'label' => 'Start earning'];
$secondaryAction = ['url' => 'login.php', 'label' => 'I have an account'];
$navItems = [
    ['label' => 'Home', 'url' => '#overview'],
    ['label' => 'Why YOLOTASK', 'url' => '#features'],
    ['label' => 'How it works', 'url' => '#how-it-works'],
];
$features = [
    ['icon' => '01', 'tag' => 'TASKS', 'title' => 'Tasks that fit your day', 'description' => 'Follow, like, review, sign up or share — each task shows the reward and exact instructions before you start.'],
    ['icon' => '02', 'tag' => 'FAIR REVIEW', 'title' => 'Clear, honest approvals', 'description' => 'Every proof is checked against the advertiser\'s instructions, with an appeal route if you believe a decision is wrong.'],
    ['icon' => '03', 'tag' => 'SECURE', 'title' => 'Your account, protected', 'description' => 'Hashed passwords, protected sessions and one-person-one-account rules keep the marketplace fair for everyone.'],
];
$featuresHeading = 'Why earners choose YOLOTASK';
require YOLOTASK_APP_ROOT . '/shared/views/layouts/public.php';
