<?php
declare(strict_types=1);

/**
 * AfrStar payout (withdrawal) callback.
 * Public URL: https://staging.yolotask.com.ng/hooks/afrstar/payout.php
 * (served by apps/main/public/hooks/afrstar/payout.php). See hooks/README.md.
 */
require_once __DIR__ . '/afrstar_callback.php';
yolotask_afrstar_callback('payout');
