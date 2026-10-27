<?php
// Example only. Copy into the private runtime config directory and edit there.
return [
    'environment' => 'development',
    'debug' => false,
    'app_name' => 'YOLOTASK V2',
    'base_urls' => [
        'main' => 'https://example.test',
        'earner' => 'https://earner.example.test',
        'advertiser' => 'https://advertiser.example.test',
        'reviewer' => 'https://reviewer.example.test',
        'admin' => 'https://admin.example.test',
    ],
    'session' => [
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ],
];
