<?php
declare(strict_types=1);
$authMode = 'login';
$emailValue = is_string($_POST['email'] ?? null) ? $_POST['email'] : '';
require YOLOTASK_APP_ROOT . '/shared/views/layouts/auth.php';
