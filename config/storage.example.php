<?php
// Example only. Private runtime storage (proof screenshots, logs) must be
// outside every document root. Set it in config-private/app.local.php as:
//   'storage' => ['private_root' => '/home/CPANEL_USER/yolotask-v2-live/runtime'],
// When unset, YOLOTASK uses env YOLOTASK_STORAGE_DIR, then
// ~/yolotask-v2-live/runtime (next to config-private). Proofs go to <root>/proofs.
return ['private_root' => '/home/CPANEL_USER/yolotask-v2-live/runtime'];
