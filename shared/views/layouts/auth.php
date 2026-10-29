<?php
declare(strict_types=1);

/**
 * Shared sign-in / registration view for the earner and advertiser portals.
 * Expected from the route: $authMode ('login'|'register'), $roleTitle, $csrfToken,
 * optional $error, $flash, $emailValue, $nameValue.
 */
$appName = yolotask_brand_name();
$authMode = ($authMode ?? 'login') === 'register' ? 'register' : 'login';
$roleTitle = is_string($roleTitle ?? null) ? $roleTitle : 'Earner';
$isAdvertiser = $roleTitle === 'Advertiser';
$csrfToken = is_string($csrfToken ?? null) ? $csrfToken : '';
$error = is_string($error ?? null) ? $error : null;
$flash = is_string($flash ?? null) ? $flash : null;
$emailValue = is_string($emailValue ?? null) ? $emailValue : '';
$nameValue = is_string($nameValue ?? null) ? $nameValue : '';
$mainUrl = yolotask_portal_url('main');

if ($authMode === 'login') {
    $pageTitle = $roleTitle . ' sign in';
    $eyebrow = 'Welcome back';
    $heading = 'Sign in to your ' . strtolower($roleTitle) . ' account';
    $intro = $isAdvertiser
        ? 'Manage your campaigns, track results and fund promotions from one place.'
        : 'Pick up where you left off and keep earning from tasks that fit your time.';
} else {
    $pageTitle = 'Create ' . strtolower($roleTitle) . ' account';
    $eyebrow = $isAdvertiser ? 'Grow with YOLOTASK' : 'Start earning';
    $heading = $isAdvertiser ? 'Create your advertiser account' : 'Create your earner account';
    $intro = $isAdvertiser
        ? 'Reach real people who complete verified actions for your brand.'
        : 'Join YOLOTASK and earn by completing simple, verified digital tasks.';
}
?>
<!doctype html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#0A1628">
  <meta name="robots" content="noindex">
  <title><?= h($pageTitle) ?> · <?= h($appName) ?></title>
  <script>(function(){try{var t=localStorage.getItem('yolo_theme');if(!t){t='dark';}document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>
  <link rel="stylesheet" href="<?= h(yolotask_asset('assets/css/app.css')) ?>">
  <link rel="manifest" href="manifest.json">
  <script src="<?= h(yolotask_asset('assets/js/ui.js')) ?>" defer></script>
</head>
<body class="auth-page">
  <a class="skip-link" href="#auth-form">Skip to form</a>
  <header class="site-header">
    <div class="container header-inner">
      <a class="brand" href="index.php" aria-label="<?= h($appName) ?> <?= h(strtolower($roleTitle)) ?> home"><span class="brand-mark" aria-hidden="true">Y</span><span><?= h($appName) ?></span></a>
      <div class="header-actions">
        <span class="badge badge-info"><?= h($roleTitle) ?></span>
        <button class="theme-toggle" type="button" data-theme-toggle aria-label="Switch colour theme">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
        </button>
      </div>
    </div>
  </header>

  <main id="auth-form" class="container page page-enter auth-main">
    <section class="card card-glow auth-card" aria-labelledby="auth-title">
      <p class="eyebrow text-primary text-xs fw-bold"><?= h(strtoupper($eyebrow)) ?></p>
      <h1 id="auth-title"><?= h($heading) ?></h1>
      <p class="text-faint"><?= h($intro) ?></p>

      <?php if ($flash !== null): ?>
        <div class="toast toast-success" role="status"><span><?= h($flash) ?></span><button type="button" class="btn btn-icon btn-ghost btn-sm" data-toast-dismiss aria-label="Dismiss">×</button></div>
      <?php endif; ?>
      <?php if ($error !== null): ?>
        <div class="toast toast-error" role="alert"><span><?= h($error) ?></span><button type="button" class="btn btn-icon btn-ghost btn-sm" data-toast-dismiss aria-label="Dismiss">×</button></div>
      <?php endif; ?>

      <?php if ($authMode === 'login'): ?>
        <form method="post" action="login.php" novalidate>
          <input type="hidden" name="_csrf" value="<?= h($csrfToken) ?>">
          <div class="field">
            <label class="label" for="email">Email address</label>
            <input class="input" id="email" name="email" type="email" maxlength="254" inputmode="email" autocomplete="username" required value="<?= h($emailValue) ?>">
          </div>
          <div class="field">
            <label class="label" for="password">Password</label>
            <input class="input" id="password" name="password" type="password" autocomplete="current-password" required>
          </div>
          <button class="btn btn-primary btn-lg btn-block" type="submit">Sign in</button>
        </form>
        <p class="text-sm text-center auth-switch">New to YOLOTASK? <a href="register.php">Create <?= $isAdvertiser ? 'an advertiser' : 'an earner' ?> account</a></p>
      <?php else: ?>
        <form method="post" action="register.php" novalidate>
          <input type="hidden" name="_csrf" value="<?= h($csrfToken) ?>">
          <div class="field">
            <label class="label" for="display_name"><?= $isAdvertiser ? 'Your name or business name' : 'Full name' ?></label>
            <input class="input" id="display_name" name="display_name" type="text" maxlength="80" autocomplete="name" required value="<?= h($nameValue) ?>">
          </div>
          <div class="field">
            <label class="label" for="email">Email address</label>
            <input class="input" id="email" name="email" type="email" maxlength="254" inputmode="email" autocomplete="email" required value="<?= h($emailValue) ?>">
          </div>
          <div class="field">
            <label class="label" for="password">Password</label>
            <input class="input" id="password" name="password" type="password" minlength="12" maxlength="72" autocomplete="new-password" aria-describedby="password-hint" required>
            <p class="input-hint" id="password-hint">At least 12 characters. Don't reuse a password from another site.</p>
          </div>
          <div class="field">
            <label class="label" for="password_confirmation">Confirm password</label>
            <input class="input" id="password_confirmation" name="password_confirmation" type="password" minlength="12" maxlength="72" autocomplete="new-password" required>
          </div>
          <button class="btn btn-primary btn-lg btn-block" type="submit">Create account</button>
        </form>
        <p class="text-xs text-faint text-center">By creating an account you agree to the <a href="<?= h($mainUrl) ?>/terms.php">Terms</a> and <a href="<?= h($mainUrl) ?>/privacy.php">Privacy Policy</a>.</p>
        <p class="text-sm text-center auth-switch">Already have an account? <a href="login.php">Sign in</a></p>
      <?php endif; ?>
    </section>
  </main>

  <footer class="site-footer">
    <div class="container flex-between flex-wrap text-xs text-faint">
      <span>© <?= date('Y') ?> YOLOTASK by Nifelux</span>
      <nav class="flex" aria-label="Legal"><a href="<?= h($mainUrl) ?>/terms.php">Terms</a><a href="<?= h($mainUrl) ?>/privacy.php">Privacy</a><a href="<?= h($mainUrl) ?>/refunds.php">Refunds</a></nav>
    </div>
  </footer>
</body>
</html>
