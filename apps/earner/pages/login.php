<?php
$appName = (string) (yolotask_config()['app_name'] ?? 'YOLOTASK V2');
$emailValue = is_string($_POST['email'] ?? null) ? $_POST['email'] : '';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#102b46">
  <title>Earner sign in · <?= h($appName) ?></title>
  <link rel="stylesheet" href="assets/css/app.css">
  <link rel="stylesheet" href="assets/css/auth.css">
  <script src="assets/js/ui.js" defer></script>
</head>
<body class="auth-body">
  <a class="skip-link" href="#auth-form">Skip to form</a>
  <header class="topbar"><div class="topbar-inner">
    <a class="brand" href="index.php" aria-label="<?= h($appName) ?> earner home"><span class="brand-mark" aria-hidden="true">Y</span><span><?= h($appName) ?></span></a>
    <span class="glass-badge badge-role">Earner</span>
  </div></header>
  <main id="auth-form" class="auth-wrap">
    <section class="auth-card" aria-labelledby="auth-title">
      <p class="eyebrow">WELCOME BACK</p>
      <h1 id="auth-title">Sign in</h1>
      <p class="auth-intro">Use your earner account credentials to continue to the YOLOTASK staging workspace.</p>
      <div class="auth-chip-row" aria-label="Security features"><span class="glass-chip">Private session</span><span class="glass-chip chip-mint">Secure connection</span></div>
      <?php if (isset($flash) && is_string($flash)): ?><div class="glass-toast toast-success" role="status" aria-live="polite"><span class="toast-icon" aria-hidden="true">✓</span><div><strong>Account update</strong><p><?= h($flash) ?></p></div><button class="toast-dismiss" type="button" data-toast-dismiss aria-label="Dismiss message">×</button></div><?php endif; ?>
      <?php if (isset($error) && is_string($error)): ?><div class="glass-toast toast-error" role="alert"><span class="toast-icon" aria-hidden="true">!</span><div><strong>Sign-in needs attention</strong><p><?= h($error) ?></p></div><button class="toast-dismiss" type="button" data-toast-dismiss aria-label="Dismiss error">×</button></div><?php endif; ?>
      <form method="post" action="login.php">
        <input type="hidden" name="_csrf" value="<?= h($csrfToken) ?>">
        <label for="email">Email address</label>
        <input id="email" name="email" type="email" maxlength="254" inputmode="email" autocomplete="username" required value="<?= h($emailValue) ?>">
        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>
        <button class="auth-submit" type="submit">Sign in securely</button>
      </form>
      <p class="auth-switch">New to YOLOTASK? <a href="register.php">Create an earner account</a></p>
      <p class="auth-disclaimer">Staging only. Task activity and all financial actions are disabled in this build.</p>
    </section>
  </main>
  <footer class="site-footer auth-footer"><a class="footer-brand" href="index.php"><span class="brand-mark brand-mark-small" aria-hidden="true">Y</span><span><?= h($appName) ?></span></a><p>Account access for the YOLOTASK staging environment.</p><span class="footer-status"><span class="status-indicator" aria-hidden="true"></span>No live financial activity</span></footer>
</body>
</html>
