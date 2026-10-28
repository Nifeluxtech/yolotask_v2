<?php
declare(strict_types=1);

define('YOLOTASK_APP_ROLE', 'main');
define('YOLOTASK_APP_ROOT', dirname(__DIR__, 3));
require_once YOLOTASK_APP_ROOT . '/shared/bootstrap.php';

$appName = (string) (yolotask_config()['app_name'] ?? 'YOLOTASK V2');
$siteHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? 'staging.yolotask.com.ng'));
$siteHost = preg_replace('/:\\d+$/', '', $siteHost) ?? $siteHost;
$siteHost = preg_replace('/^www\\./', '', $siteHost) ?? $siteHost;
if (!in_array($siteHost, ['staging.yolotask.com.ng', 'yolotask.com.ng'], true)) $siteHost = 'staging.yolotask.com.ng';
$isStaging = $siteHost === 'staging.yolotask.com.ng';
$rootDomain = 'yolotask.com.ng';
$earnerHost = ($isStaging ? 'earner-staging.' : 'earner.') . $rootDomain;
$advertiserHost = ($isStaging ? 'advertiser-staging.' : 'advertiser.') . $rootDomain;
$earnerUrl = 'https://' . $earnerHost;
$advertiserUrl = 'https://' . $advertiserHost;
$roleTitle = 'YOLOTASK';
$roleSlug = 'main';
$subtitle = 'A clearer way to earn, promote, and grow online.';
$description = 'YOLOTASK is being rebuilt on a fresh, role-separated foundation. Start with a secure staging account while we verify the core experience before enabling marketplace activity.';
$heroChips = ['MOBILE-FIRST EXPERIENCE', 'ROLE-BASED PORTALS', 'V2 STAGING'];
$statusMessage = 'Earner and advertiser account creation and sign-in are available for staging tests. Tasks, campaigns, payments, and balances are not active.';
$availabilityTitle = 'What you can test today';
$availabilityBody = 'The authentication foundation is live on the earner and advertiser staging subdomains. This is not yet a live marketplace and no real task or financial activity can be completed.';
$headerAction = ['url' => $earnerUrl . '/login.php', 'label' => 'Earner sign in'];
$primaryAction = ['url' => $earnerUrl . '/register.php', 'label' => 'Join as an earner'];
$secondaryAction = ['url' => $advertiserUrl . '/register.php', 'label' => 'Advertise with us'];
$navItems = [
    ['label' => 'Overview', 'url' => '#overview'],
    ['label' => 'What we value', 'url' => '#value'],
    ['label' => 'Role access', 'url' => '#access'],
];
$features = [
    ['icon' => '01', 'tag' => 'EARNERS', 'title' => 'Find work that fits your time', 'description' => 'The earner portal is the starting point for account creation and secure sign-in. Task discovery will follow after the core tests pass.'],
    ['icon' => '02', 'tag' => 'ADVERTISERS', 'title' => 'Prepare to grow with confidence', 'description' => 'Advertisers have a separate portal for registration and sign-in. Campaign creation and funding remain disabled in this staging build.'],
    ['icon' => '03', 'tag' => 'SAFETY FIRST', 'title' => 'Build trust into the foundation', 'description' => 'Role boundaries, CSRF protection, session rotation, throttling, and a fresh V2 database are being verified before features expand.'],
];
require YOLOTASK_APP_ROOT . '/shared/views/layouts/public.php';
