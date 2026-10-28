<?php
declare(strict_types=1);

define('YOLOTASK_APP_ROLE', 'reviewer');
define('YOLOTASK_APP_ROOT', dirname(__DIR__, 3));
require_once YOLOTASK_APP_ROOT . '/shared/bootstrap.php';

$appName = (string) (yolotask_config()['app_name'] ?? 'YOLOTASK V2');
$roleTitle = 'Reviewer';
$roleSlug = 'reviewer';
$subtitle = 'Fair decisions start with a clear review process.';
$description = 'The reviewer workspace will support careful, consistent checks of task submissions. Access is staff-created; self-registration and reviewer sign-in are not enabled in this staging phase.';
$heroChips = ['REVIEWER PORTAL', 'STAFF-CONTROLLED ACCESS', 'V2 STAGING'];
$statusMessage = 'Reviewer access is not yet enabled. This page is a design preview only; review queues and decision actions are unavailable.';
$availabilityTitle = 'Reviewer access is not enabled yet';
$availabilityBody = 'Reviewer accounts are provisioned by authorized staff. The review queue and decision workflows are not active in this build.';
$headerAction = null;
$primaryAction = null;
$secondaryAction = null;
$navItems = [
    ['label' => 'Overview', 'url' => '#overview'],
    ['label' => 'Review values', 'url' => '#value'],
    ['label' => 'Access notes', 'url' => '#access'],
];
$features = [
    ['icon' => '01', 'tag' => 'CONSISTENCY', 'title' => 'Clear review standards', 'description' => 'Review guidance and evidence requirements will be made visible before the reviewer queue is activated.'],
    ['icon' => '02', 'tag' => 'COMING NEXT', 'title' => 'A manageable review queue', 'description' => 'Queue claiming, release, and decision history will be tested after role authentication and task submissions are ready.'],
    ['icon' => '03', 'tag' => 'STAFF ONLY', 'title' => 'No public reviewer sign-up', 'description' => 'Reviewer and administrator roles cannot be created through public registration forms.'],
];
require YOLOTASK_APP_ROOT . '/shared/views/layouts/public.php';
