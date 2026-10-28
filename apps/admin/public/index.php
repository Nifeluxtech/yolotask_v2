<?php
declare(strict_types=1);

define('YOLOTASK_APP_ROLE', 'admin');
define('YOLOTASK_APP_ROOT', dirname(__DIR__, 3));
require_once YOLOTASK_APP_ROOT . '/shared/bootstrap.php';

$appName = (string) (yolotask_config()['app_name'] ?? 'YOLOTASK V2');
$roleTitle = 'Admin';
$roleSlug = 'admin';
$subtitle = 'Strong operations begin with strong guardrails.';
$description = 'The admin workspace will provide controlled tools for users, campaigns, reviews, and platform settings. Administrative access is staff-created and is not enabled in this staging phase.';
$heroChips = ['ADMIN PORTAL', 'RESTRICTED ACCESS', 'V2 STAGING'];
$statusMessage = 'Administrative sign-in and controls are not enabled. This page is a design preview; no admin actions are available.';
$availabilityTitle = 'Admin access is not enabled yet';
$availabilityBody = 'Administrator accounts are created only through an approved staff process. Admin authentication and management actions are not part of this staging release.';
$headerAction = null;
$primaryAction = null;
$secondaryAction = null;
$navItems = [
    ['label' => 'Overview', 'url' => '#overview'],
    ['label' => 'Platform values', 'url' => '#value'],
    ['label' => 'Access notes', 'url' => '#access'],
];
$features = [
    ['icon' => '01', 'tag' => 'CONTROL', 'title' => 'Least-privilege operations', 'description' => 'Admin capabilities will be scoped by explicit permissions rather than shared or public account roles.'],
    ['icon' => '02', 'tag' => 'COMING NEXT', 'title' => 'Auditable platform actions', 'description' => 'High-impact changes will be recorded and reviewable before administrative workflows are activated.'],
    ['icon' => '03', 'tag' => 'STAFF ONLY', 'title' => 'Restricted account creation', 'description' => 'Public forms do not create administrator accounts. Staff provisioning and MFA requirements remain future gates.'],
];
require YOLOTASK_APP_ROOT . '/shared/views/layouts/public.php';
