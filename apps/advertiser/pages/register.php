<?php
$appName = (string) (yolotask_config()['app_name'] ?? 'YOLOTASK V2');
$nameValue = is_string($_POST['display_name'] ?? null) ? $_POST['display_name'] : '';
$emailValue = is_string($_POST['email'] ?? null) ? $_POST['email'] : '';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#102b46">
  <title>Create advertiser account · <?= h($appName) ?></title>
  <link rel="stylesheet" href="assets/css/app.css">
  <link rel="stylesheet" href="assets/css/auth.css">
  <script src="assets/js/ui.js" defer></script>
</head>
<body class="auth-body">
  <a class="skip-link" href="#auth-form">Skip to form</a>
  <header class="topbar"><div class="topbar-inner">
    <a class="brand" href="index.php" aria-label="<?= h($appName) ?> advertiser home"><span class="brand-mark" aria-hidden="true">Y</span><span><?= h($appName) ?></span></a>
    <span class="glass-badge badge-role">Advertiser</span>
  </div></header>
  <main id="auth-form" class="auth-wrap">
    <section class="auth-card" aria-labelledby="auth-title">
      <p class="eyebrow">ADVERTISER ACCOUNT</p>
      <h1 id="auth-title">Create your account</h1>
      <p class="auth-intro">Set up your advertiser account. Reviewer and admin accounts are created by authorized staff only.</p>
      <div class="auth-chip-row" aria-label="Account details"><span class="glass-chip">Advertiser access</span><span class="glass-chip chip-mint">Staging sign-up</span></div>
      <?php if (isset($error) && is_string($error)): ?><div class="glass-toast toast-error" role="alert"><span class="toast-icon" aria-hidden="true">!</span><div><strong>Check your details</strong><p><?= h($error) ?></p></div><button class="toast-dismiss" type="button" data-toast-dismiss aria-label="Dismiss error">×</button></div><?php endif; ?>
      <form method="post" action="register.php">
        <input type="hidden" name="_csrf" value="<?= h($csrfToken) ?>">
        <label for="display_name">Your name or business name</label>
        <input id="display_name" name="display_name" type="text" maxlength="80" autocomplete="name" required value="<?= h($nameValue) ?>">
        <label for="email">Email address</label>
        <input id="email" name="email" type="email" maxlength="254" inputmode="email" autocomplete="email" required value="<?= h($emailValue) ?>">
        <label for="password">Password</label>
        <input id="password" name="password" type="password" minlength="12" maxlength="72" autocomplete="new-password" aria-describedby="password-hint" required>
        <p class="field-hint" id="password-hint">Use at least 12 characters. Avoid reusing another site's password.</p>
        <label for="password_confirmation">Confirm password</label>
        <input id="password_confirmation" name="password_confirmation" type="password" minlength="12" maxlength="72" autocomplete="new-password" required>
        <button class="auth-submit" type="submit">Create advertiser account</button>
      </form>
      <p class="auth-switch">Already registered? <a href="login.php">Sign in</a></p>
      <p class="auth-disclaimer">Email verification is not available yet. This staging account is for authentication testing only; campaigns and payments are disabled.</p>
    </section>
  </main>
  <footer class="site-footer auth-footer"><a class="footer-brand" href="index.php"><span class="brand-mark brand-mark-small" aria-hidden="true">Y</span><span><?= h($appName) ?></span></a><p>Account access for the YOLOTASK staging environment.</p><span class="footer-status"><span class="status-indicator" aria-hidden="true"></span>No live financial activity</span></footer>
</body>
</html>
