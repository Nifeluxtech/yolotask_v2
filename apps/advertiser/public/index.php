<?php
declare(strict_types=1);

use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Security\Auth;
use YoloTask\Security\Csrf;

define('YOLOTASK_APP_ROLE', 'advertiser');
define('YOLOTASK_APP_ROOT', dirname(__DIR__, 3));
require_once YOLOTASK_APP_ROOT . '/shared/bootstrap.php';

$signedInUser = null;
$logoutCsrfToken = '';
if (isset($_SESSION['user_id'])) {
    try {
        $candidate = Auth::currentUser(Database::connection());
        if (is_array($candidate) && (string) ($candidate['role'] ?? '') === 'advertiser') {
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
$roleTitle = 'Advertiser';
$roleSlug = 'advertiser';
$subtitle = 'Real people. Real actions. Measurable growth.';
$description = 'Launch campaigns that pay real Nigerians to follow, engage, review and sign up — and only pay for actions that pass verification.';
$heroChips = ['Pay per verified action', 'Targeted reach', 'Escrow-protected budgets'];
$headerAction = ['url' => 'login.php', 'label' => 'Sign in'];
$primaryAction = ['url' => 'register.php', 'label' => 'Create advertiser account'];
$secondaryAction = ['url' => 'login.php', 'label' => 'Sign in'];
$navItems = [
    ['label' => 'Home', 'url' => '#overview'],
    ['label' => 'Benefits', 'url' => '#features'],
    ['label' => 'How it works', 'url' => '#how-it-works'],
];
$features = [
    ['icon' => '01', 'tag' => 'REACH', 'title' => 'Reach engaged people', 'description' => 'Put your brand in front of earners who complete each action by hand, following your exact instructions.'],
    ['icon' => '02', 'tag' => 'CONTROL', 'title' => 'Approve what you pay for', 'description' => 'Review every proof, approve good work and reject anything that misses the brief.'],
    ['icon' => '03', 'tag' => 'PROTECTED', 'title' => 'Budgets held safely', 'description' => 'Campaign budgets are reserved before launch and released only for approved actions.'],
];
$featuresHeading = 'Why advertisers choose YOLOTASK';
$steps = [
    ['title' => 'Create your account', 'body' => 'Set up your advertiser profile with your name or business name.'],
    ['title' => 'Launch a campaign', 'body' => 'Describe the action you want, set the reward per action and fund the budget.'],
    ['title' => 'Approve real results', 'body' => 'Review each proof and pay only for actions that meet your brief.'],
];
require YOLOTASK_APP_ROOT . '/shared/views/layouts/public.php';
