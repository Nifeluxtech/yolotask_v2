<?php
declare(strict_types=1);

define('YOLOTASK_APP_ROLE', 'main');
define('YOLOTASK_APP_ROOT', dirname(__DIR__, 3));
require_once YOLOTASK_APP_ROOT . '/shared/bootstrap.php';

$earnerUrl = yolotask_portal_url('earner');
$advertiserUrl = yolotask_portal_url('advertiser');
$appName = (string) (yolotask_config()['app_name'] ?? 'YOLOTASK');
$legalBaseUrl = '';
$roleTitle = 'Earn and grow online';
$roleSlug = 'main';
$subtitle = 'Real tasks. Real rewards. One marketplace.';
$description = 'YOLOTASK connects people who want to earn from verified digital tasks with brands that want genuine engagement. It is a promotional task marketplace — not an investment platform.';
$heroChips = ['Earners', 'Advertisers', 'Verified work'];
$headerAction = ['url' => $earnerUrl . '/login.php', 'label' => 'Sign in'];
$primaryAction = ['url' => $earnerUrl . '/register.php', 'label' => 'Start earning'];
$secondaryAction = ['url' => $advertiserUrl . '/register.php', 'label' => 'Advertise with us'];
$navItems = [
    ['label' => 'Home', 'url' => '#overview'],
    ['label' => 'Features', 'url' => '#features'],
    ['label' => 'How it works', 'url' => '#how-it-works'],
];
$features = [
    ['icon' => '01', 'tag' => 'EARNERS', 'title' => 'Earn on your schedule', 'description' => 'Pick tasks that fit your time, follow the instructions and get rewarded for every approved proof.'],
    ['icon' => '02', 'tag' => 'ADVERTISERS', 'title' => 'Pay for real results', 'description' => 'Run campaigns that reward real people for real actions, and approve only work that meets your brief.'],
    ['icon' => '03', 'tag' => 'TRUST', 'title' => 'Built for fairness', 'description' => 'Verified proofs, protected budgets and one person per account keep YOLOTASK honest for both sides.'],
];
$featuresHeading = 'One marketplace, two sides';
require YOLOTASK_APP_ROOT . '/shared/views/layouts/public.php';
