<?php
// Example only. Copy into the private runtime config directory and edit there.
return [
    'environment' => 'development',
    'debug' => false,
    'app_name' => 'YOLOTASK V2',
    // Set a private random value of at least 32 characters outside the repository.
    'security_key' => '',
    'base_urls' => [
        'main' => 'https://example.test',
        'earner' => 'https://earner.example.test',
        'advertiser' => 'https://advertiser.example.test',
        'reviewer' => 'https://reviewer.example.test',
        'admin' => 'https://admin.example.test',
    ],
    'session' => [
        'secure' => false, // Set true on every HTTPS staging/production host.
        'httponly' => true,
        'samesite' => 'Lax',
    ],
];
