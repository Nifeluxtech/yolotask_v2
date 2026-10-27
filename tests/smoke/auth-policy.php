<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/shared/autoload.php';

use YoloTask\Security\Auth;
use YoloTask\Security\Authorization;

$checks = [
    'earner self-registration allowed' => Authorization::allowsSelfRegistration('earner'),
    'advertiser self-registration allowed' => Authorization::allowsSelfRegistration('advertiser'),
    'reviewer self-registration denied' => !Authorization::allowsSelfRegistration('reviewer'),
    'admin self-registration denied' => !Authorization::allowsSelfRegistration('admin'),
    'valid registration password accepted' => Auth::validateRegistration('Test User', 'user@example.test', 'CorrectHorseBattery!') === null,
    'short password rejected' => Auth::validateRegistration('Test User', 'user@example.test', 'short') !== null,
    'invalid email rejected' => Auth::validateRegistration('Test User', 'not-an-email', 'CorrectHorseBattery!') !== null,
];
foreach ($checks as $label => $passed) {
    if (!$passed) { fwrite(STDERR, "FAIL: {$label}\n"); exit(1); }
    echo "PASS: {$label}\n";
}
