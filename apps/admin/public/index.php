<?php
declare(strict_types=1);

define('YOLOTASK_APP_ROLE', 'admin');
define('YOLOTASK_APP_ROOT', dirname(__DIR__, 3));
require_once YOLOTASK_APP_ROOT . '/shared/bootstrap.php';
header('X-Robots-Tag: noindex, nofollow');

$appName = (string) (yolotask_config()['app_name'] ?? 'YOLOTASK');
$legalBaseUrl = yolotask_portal_url('main');
$roleTitle = 'Admin';
$roleSlug = 'admin';
$subtitle = 'Strong operations, strong guardrails.';
$description = 'The admin workspace is restricted to authorised YOLOTASK operators. Accounts are created by invitation only.';
$heroChips = ['Authorised staff only', 'Audited actions'];
$headerAction = null;
$primaryAction = ['url' => yolotask_portal_url('main'), 'label' => 'Visit YOLOTASK'];
$secondaryAction = null;
$navItems = [
    ['label' => 'Home', 'url' => '#overview'],
    ['label' => 'Principles', 'url' => '#features'],
];
$features = [
    ['icon' => '01', 'tag' => 'LEAST PRIVILEGE', 'title' => 'Scoped permissions', 'description' => 'Operators see and change only what their role allows.'],
    ['icon' => '02', 'tag' => 'AUDIT', 'title' => 'Traceable changes', 'description' => 'High-impact actions are recorded for review.'],
    ['icon' => '03', 'tag' => 'SECURITY', 'title' => 'Protected access', 'description' => 'Staff accounts are invitation-only and never created through public forms.'],
];
$featuresHeading = 'Operating principles';
$steps = [
    ['title' => 'Invitation only', 'body' => 'Operator accounts are created by an existing administrator.'],
    ['title' => 'Scoped access', 'body' => 'Each operator works only within the permissions granted.'],
    ['title' => 'Full audit trail', 'body' => 'Sensitive changes are logged for later review.'],
];
require YOLOTASK_APP_ROOT . '/shared/views/layouts/public.php';
