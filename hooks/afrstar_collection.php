<?php
declare(strict_types=1);

/**
 * AfrStar collection (top-up) callback.
 * Public URL: https://staging.yolotask.com.ng/hooks/afrstar/collection.php
 * (served by apps/main/public/hooks/afrstar/collection.php). See hooks/README.md.
 */
require_once __DIR__ . '/afrstar_callback.php';
yolotask_afrstar_callback('collection');
