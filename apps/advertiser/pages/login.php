<?php
$appName = (string) (yolotask_config()['app_name'] ?? 'YOLOTASK V2');
$emailValue = is_string($_POST['email'] ?? null) ? $_POST['email'] : '';
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"><meta name="theme-color" content="#102b46"><title>Advertiser sign in · <?= h($appName) ?></title><link rel="stylesheet" href="assets/css/app.css"><link rel="stylesheet" href="assets/css/auth.css"></head>
<body><a class="skip-link" href="#auth-form">Skip to form</a><header class="topbar"><a class="brand" href="index.php"><span class="brand-mark" aria-hidden="true">Y</span><span><?= h($appName) ?></span></a><span class="role-pill">Advertiser</span></header>
<main id="auth-form" class="auth-wrap"><section class="auth-card"><p class="eyebrow">WELCOME BACK</p><h1>Sign in</h1><p class="auth-intro">Use your advertiser account credentials.</p>
<?php if (isset($flash) && is_string($flash)): ?><p class="form-message success" role="status"><?= h($flash) ?></p><?php endif; ?><?php if (isset($error) && is_string($error)): ?><p class="form-message error" role="alert"><?= h($error) ?></p><?php endif; ?>
<form method="post" action="login.php"><input type="hidden" name="_csrf" value="<?= h($csrfToken) ?>"><label for="email">Email address</label><input id="email" name="email" type="email" maxlength="254" inputmode="email" autocomplete="username" required value="<?= h($emailValue) ?>"><label for="password">Password</label><input id="password" name="password" type="password" autocomplete="current-password" required><button class="auth-submit" type="submit">Sign in</button></form>
<p class="auth-switch">New to YOLOTASK? <a href="register.php">Create an advertiser account</a></p><p class="auth-disclaimer">Password reset and email verification are not implemented in this phase.</p></section></main></body></html>
