<?php
declare(strict_types=1);
$errorCode = is_string($errorCode ?? null) ? $errorCode : '500';
$errorTitle = is_string($errorTitle ?? null) ? $errorTitle : 'Something went wrong';
$errorBody = is_string($errorBody ?? null) ? $errorBody : '';
$homeUrl = function_exists('yolotask_portal_url') && defined('YOLOTASK_APP_ROLE') ? yolotask_portal_url((string) YOLOTASK_APP_ROLE) : '/';
?>
<!doctype html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#0A1628">
  <meta name="robots" content="noindex">
  <title><?= htmlspecialchars($errorTitle, ENT_QUOTES, 'UTF-8') ?> · YOLOTASK</title>
  <script>(function(){try{var t=localStorage.getItem('yolo_theme');if(t){document.documentElement.setAttribute('data-theme',t);}}catch(e){}})();</script>
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="error-page">
  <main class="container page page-enter error-main">
    <section class="card card-glow text-center error-card">
      <a class="brand" href="<?= htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8') ?>/"><span class="brand-mark" aria-hidden="true">Y</span><span>YOLOTASK</span></a>
      <p class="text-xs fw-bold text-primary">ERROR <?= htmlspecialchars($errorCode, ENT_QUOTES, 'UTF-8') ?></p>
      <h1><?= htmlspecialchars($errorTitle, ENT_QUOTES, 'UTF-8') ?></h1>
      <p class="text-faint"><?= htmlspecialchars($errorBody, ENT_QUOTES, 'UTF-8') ?></p>
      <a class="btn btn-primary" href="<?= htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8') ?>/">Back to YOLOTASK</a>
    </section>
  </main>
</body>
</html>
