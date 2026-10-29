<?php
declare(strict_types=1);
// Public entry for the AfrStar payout callback. Logic lives outside the document root.
define('YOLOTASK_APP_ROOT', dirname(__DIR__, 5));
require YOLOTASK_APP_ROOT . '/hooks/afrstar_payout.php';
