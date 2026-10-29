<?php
declare(strict_types=1);
$authMode = 'register';
$nameValue = is_string($_POST['display_name'] ?? null) ? $_POST['display_name'] : '';
$emailValue = is_string($_POST['email'] ?? null) ? $_POST['email'] : '';
require YOLOTASK_APP_ROOT . '/shared/views/layouts/auth.php';
