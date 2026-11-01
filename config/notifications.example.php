<?php
// Example only; copy to config-private/notifications.php to switch on web push.
// No file (or enabled => false) = push disabled; the in-app inbox and email
// notifications work without it. Never commit real keys.
return [
    'onesignal' => [
        'enabled' => false,
        'app_id' => '',   // OneSignal App ID
        'api_key' => '',  // OneSignal REST API key
    ],
];
