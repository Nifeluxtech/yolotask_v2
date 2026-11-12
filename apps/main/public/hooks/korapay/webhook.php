<?php
declare(strict_types=1);
// Public entry for the Korapay webhook. Logic lives outside the document root.
define('YOLOTASK_APP_ROOT', dirname(__DIR__, 5));
require YOLOTASK_APP_ROOT . '/hooks/korapay_webhook.php';
