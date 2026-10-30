<?php
declare(strict_types=1);
define('YOLOTASK_APP_ROLE', 'earner');
define('YOLOTASK_APP_ROOT', dirname(__DIR__, 3));
define('YOLOTASK_SUBMISSION_DETAIL', true);
require_once YOLOTASK_APP_ROOT . '/shared/bootstrap.php';
require YOLOTASK_APP_ROOT . '/shared/routes/earner-submissions.php';
