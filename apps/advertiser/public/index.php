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

$appName = (string) (yolotask_config()['app_name'] ?? 'YOLOTASK V2');
$roleTitle = 'Advertiser';
$roleSlug = 'advertiser';
$subtitle = 'Build the right campaign, the right way.';
$description = 'The advertiser portal is where brands will prepare and manage campaigns. For now, create a staging account and help us verify the secure sign-in foundation.';
$heroChips = ['ADVERTISER PORTAL', 'SECURE SIGN-IN', 'V2 STAGING'];
$statusMessage = $signedInUser !== null
    ? 'You are signed in. Campaign creation, funding, and payouts remain disabled in this staging build.'
    : 'Advertiser sign-up and sign-in are open for staging verification. Campaign creation, funding, and payouts are not active.';
$availabilityTitle = 'Advertiser staging access';
$availabilityBody = 'You can test account creation, validation, sign-in, session rotation, and sign-out. Campaigns, deposits, and all balance-changing operations remain unavailable.';
$headerAction = ['url' => 'login.php', 'label' => 'Sign in'];
$primaryAction = ['url' => 'register.php', 'label' => 'Create advertiser account'];
$secondaryAction = ['url' => 'login.php', 'label' => 'I already have an account'];
$navItems = [
    ['label' => 'Overview', 'url' => '#overview'],
    ['label' => 'For brands', 'url' => '#value'],
    ['label' => 'Access notes', 'url' => '#access'],
];
$features = [
    ['icon' => '01', 'tag' => 'ACCOUNT', 'title' => 'Your business, your space', 'description' => 'The advertiser role uses a separate entry point and is kept distinct from earner and staff access.'],
    ['icon' => '02', 'tag' => 'COMING NEXT', 'title' => 'Campaign tools with guardrails', 'description' => 'Campaign setup, targeting, review, and allocation will be built after account and database behavior has passed staging tests.'],
    ['icon' => '03', 'tag' => 'NO LIVE FUNDS', 'title' => 'Funding is not switched on', 'description' => 'Deposits, campaign charges, wallet adjustments, and advertiser payouts are disabled in this staging build.'],
];
require YOLOTASK_APP_ROOT . '/shared/views/layouts/public.php';
