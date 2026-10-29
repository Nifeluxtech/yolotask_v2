<?php
declare(strict_types=1);

define('YOLOTASK_APP_ROLE', 'reviewer');
define('YOLOTASK_APP_ROOT', dirname(__DIR__, 3));
require_once YOLOTASK_APP_ROOT . '/shared/bootstrap.php';
header('X-Robots-Tag: noindex, nofollow');

$appName = (string) (yolotask_config()['app_name'] ?? 'YOLOTASK');
$legalBaseUrl = yolotask_portal_url('main');
$roleTitle = 'Reviewer';
$roleSlug = 'reviewer';
$subtitle = 'Fair decisions keep YOLOTASK trusted.';
$description = 'The reviewer workspace is for authorised YOLOTASK staff who check submitted proofs and resolve escalations.';
$heroChips = ['Authorised staff only', 'Audited actions'];
$headerAction = null;
$primaryAction = ['url' => yolotask_portal_url('main'), 'label' => 'Visit YOLOTASK'];
$secondaryAction = null;
$navItems = [
    ['label' => 'Home', 'url' => '#overview'],
    ['label' => 'Principles', 'url' => '#features'],
];
$features = [
    ['icon' => '01', 'tag' => 'ACCURACY', 'title' => 'Check every proof carefully', 'description' => 'Each decision is measured against the advertiser\'s instructions, never personal preference.'],
    ['icon' => '02', 'tag' => 'FAIRNESS', 'title' => 'Treat both sides equally', 'description' => 'Earners and advertisers deserve consistent, explainable outcomes.'],
    ['icon' => '03', 'tag' => 'ACCOUNTABILITY', 'title' => 'Every action is recorded', 'description' => 'Review decisions are logged so they can be audited and appealed.'],
];
$featuresHeading = 'How we review';
$steps = [
    ['title' => 'Invitation only', 'body' => 'Reviewer accounts are issued by YOLOTASK operations.'],
    ['title' => 'Check against the brief', 'body' => 'Each proof is compared with the advertiser\'s instructions.'],
    ['title' => 'Record the decision', 'body' => 'Every outcome is logged with a reason that can be appealed.'],
];
require YOLOTASK_APP_ROOT . '/shared/views/layouts/public.php';
