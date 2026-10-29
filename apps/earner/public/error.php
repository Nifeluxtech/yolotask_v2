<?php
declare(strict_types=1);
define('YOLOTASK_APP_ROLE', 'earner');
define('YOLOTASK_APP_ROOT', dirname(__DIR__, 3));
require_once YOLOTASK_APP_ROOT . '/shared/bootstrap.php';
$code = (string) ($_GET['code'] ?? '404');
$code = in_array($code, ['403', '404', '500'], true) ? $code : '404';
http_response_code((int) $code);
require YOLOTASK_APP_ROOT . '/shared/views/errors/' . $code . '.php';
