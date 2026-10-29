<?php
declare(strict_types=1);

/**
 * Shared sign-in, sign-up, password and verification view for every portal.
 * Expected from the route: $authMode ('login'|'register'|'forgot'|'reset'|'verify'|'done'),
 * $roleTitle, $csrfToken; optional $error, $flash, $emailValue, $nameValue,
 * $resetToken, $isInvite, $verifyEmail, $resendWait, $formStamp, $doneHeading, $doneBody.
 */
$appName = yolotask_brand_name();
$modes = ['login', 'register', 'forgot', 'reset', 'verify', 'done'];
$authMode = in_array($authMode ?? 'login', $modes, true) ? $authMode : 'login';
$roleTitle = is_string($roleTitle ?? null) ? $roleTitle : 'Earner';
$roleLower = strtolower($roleTitle);
$isAdvertiser = $roleTitle === 'Advertiser';
$isStaff = in_array($roleTitle, ['Reviewer', 'Admin'], true);
$csrfToken = is_string($csrfToken ?? null) ? $csrfToken : '';
$error = is_string($error ?? null) ? $error : null;
$flash = is_string($flash ?? null) ? $flash : null;
$emailValue = is_string($emailValue ?? null) ? $emailValue : '';
$nameValue = is_string($nameValue ?? null) ? $nameValue : '';
$resetToken = is_string($resetToken ?? null) ? $resetToken : '';
$isInvite = (bool) ($isInvite ?? false);
$verifyEmail = is_string($verifyEmail ?? null) ? $verifyEmail : '';
$resendWait = (int) ($resendWait ?? 0);
$formStamp = is_string($formStamp ?? null) ? $formStamp : '';
$mainUrl = yolotask_portal_url('main');
$article = $isAdvertiser || $isStaff && $roleTitle === 'Admin' ? 'an' : 'a';

switch ($authMode) {
    case 'register':
        $pageTitle = 'Create ' . $roleLower . ' account';
        $eyebrow = $isAdvertiser ? 'Grow with YOLOTASK' : 'Start earning';
        $heading = 'Create your ' . $roleLower . ' account';
        $intro = $isAdvertiser
            ? 'Reach real people who complete verified actions for your brand.'
            : 'Join YOLOTASK and earn by completing simple, verified digital tasks.';
        break;
    case 'forgot':
        $pageTitle = 'Reset password';
        $eyebrow = 'Account recovery';
        $heading = 'Forgot your password?';
        $intro = 'Enter the email on your ' . $roleLower . ' account and we will send you a link to choose a new one.';
        break;
    case 'reset':
        $pageTitle = $isInvite ? 'Set your password' : 'Choose a new password';
        $eyebrow = $isInvite ? 'Welcome to the team' : 'Account recovery';
        $heading = $isInvite ? 'Set your password' : 'Choose a new password';
        $intro = $isInvite
            ? 'Create a password to activate your YOLOTASK ' . $roleLower . ' account.'
            : 'Pick a strong password you have not used before. Every device will be signed out.';
        break;
    case 'verify':
        $pageTitle = 'Confirm your email';
        $eyebrow = 'One last step';
        $heading = 'Confirm your email';
        $intro = 'We sent a 6-digit code to ' . ($verifyEmail !== '' ? $verifyEmail : 'your email') . '. Enter it below, or tap the link in the email.';
        break;
    case 'done':
        $pageTitle = is_string($doneHeading ?? null) ? $doneHeading : 'Done';
        $eyebrow = 'All set';
        $heading = $pageTitle;
        $intro = is_string($doneBody ?? null) ? $doneBody : '';
        break;
    default:
        $pageTitle = $roleTitle . ' sign in';
        $eyebrow = $isStaff ? 'Staff access' : 'Welcome back';
        $heading = 'Sign in to your ' . $roleLower . ' account';
        $intro = match ($roleTitle) {
            'Advertiser' => 'Manage your campaigns, track results and fund promotions from one place.',
            'Reviewer' => 'Review submitted proofs fairly and keep the marketplace honest.',
            'Admin' => 'Restricted to authorised YOLOTASK operators. Every action is logged.',
            default => 'Pick up where you left off and keep earning from tasks that fit your time.',
        };
}

$passwordFields = static function (string $label = 'Password') : string {
    return '<div class="field">'
        . '<label class="label" for="password">' . h($label) . '</label>'
        . '<input class="input" id="password" name="password" type="password" minlength="12" maxlength="72" autocomplete="new-password" aria-describedby="password-hint" required>'
        . '<p class="input-hint" id="password-hint">At least 12 characters. Don\'t reuse a password from another site.</p>'
        . '</div><div class="field">'
        . '<label class="label" for="password_confirmation">Confirm password</label>'
        . '<input class="input" id="password_confirmation" name="password_confirmation" type="password" minlength="12" maxlength="72" autocomplete="new-password" required>'
        . '</div>';
};
$botFields = $formStamp === '' ? '' :
    '<input type="hidden" name="_stamp" value="' . h($formStamp) . '">'
    . '<div class="hp-field" aria-hidden="true"><label for="website">Website</label><input id="website" name="website" type="text" tabindex="-1" autocomplete="off"></div>';
?>
<!doctype html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#0A1628">
  <meta name="robots" content="noindex">
  <meta name="referrer" content="same-origin">
  <title><?= h($pageTitle) ?> · <?= h($appName) ?></title>
  <script>(function(){try{var t=localStorage.getItem('yolo_theme');if(t!=='light'&&t!=='dark'){t='dark';}document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>
  <link rel="stylesheet" href="<?= h(yolotask_asset('assets/css/app.css')) ?>">
  <?php if (!$isStaff): ?><link rel="manifest" href="manifest.json"><?php endif; ?>
  <script src="<?= h(yolotask_asset('assets/js/ui.js')) ?>" defer></script>
</head>
<body class="auth-page">
  <a class="skip-link" href="#auth-form">Skip to form</a>
  <header class="site-header">
    <div class="container header-inner">
      <a class="brand" href="index.php" aria-label="<?= h($appName) ?> <?= h($roleLower) ?> home"><span class="brand-mark" aria-hidden="true">Y</span><span><?= h($appName) ?></span></a>
      <div class="header-actions">
        <span class="badge badge-info"><?= h($roleTitle) ?></span>
        <button class="theme-toggle" type="button" data-theme-toggle aria-label="Switch colour theme">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
        </button>
      </div>
    </div>
  </header>

  <main id="auth-form" class="container page auth-main">
    <section class="card card-glow auth-card" aria-labelledby="auth-title">
      <p class="eyebrow text-primary text-xs fw-bold"><?= h(strtoupper($eyebrow)) ?></p>
      <h1 id="auth-title"><?= h($heading) ?></h1>
      <?php if ($intro !== ''): ?><p class="text-faint"><?= h($intro) ?></p><?php endif; ?>

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
            <div class="flex-between"><label class="label" for="password">Password</label><a class="text-xs" href="forgot-password.php">Forgot password?</a></div>
            <input class="input" id="password" name="password" type="password" autocomplete="current-password" required>
          </div>
          <button class="btn btn-primary btn-lg btn-block" type="submit">Sign in</button>
        </form>
        <?php if ($isStaff): ?>
          <p class="text-xs text-faint text-center auth-switch">Staff accounts are created by an administrator. There is no public sign-up.</p>
        <?php else: ?>
          <p class="text-sm text-center auth-switch">New to YOLOTASK? <a href="register.php">Create <?= $isAdvertiser ? 'an advertiser' : 'an earner' ?> account</a></p>
        <?php endif; ?>

      <?php elseif ($authMode === 'register'): ?>
        <form method="post" action="register.php" novalidate>
          <input type="hidden" name="_csrf" value="<?= h($csrfToken) ?>">
          <?= $botFields ?>
          <div class="field">
            <label class="label" for="display_name"><?= $isAdvertiser ? 'Your name or business name' : 'Full name' ?></label>
            <input class="input" id="display_name" name="display_name" type="text" maxlength="80" autocomplete="name" required value="<?= h($nameValue) ?>">
          </div>
          <div class="field">
            <label class="label" for="email">Email address</label>
            <input class="input" id="email" name="email" type="email" maxlength="254" inputmode="email" autocomplete="email" required value="<?= h($emailValue) ?>">
          </div>
          <?= $passwordFields() ?>
          <button class="btn btn-primary btn-lg btn-block" type="submit">Create account</button>
        </form>
        <p class="text-xs text-faint text-center">By creating an account you agree to the <a href="<?= h($mainUrl) ?>/terms.php">Terms</a> and <a href="<?= h($mainUrl) ?>/privacy.php">Privacy Policy</a>.</p>
        <p class="text-sm text-center auth-switch">Already have an account? <a href="login.php">Sign in</a></p>

      <?php elseif ($authMode === 'forgot'): ?>
        <form method="post" action="forgot-password.php" novalidate>
          <input type="hidden" name="_csrf" value="<?= h($csrfToken) ?>">
          <?= $botFields ?>
          <div class="field">
            <label class="label" for="email">Email address</label>
            <input class="input" id="email" name="email" type="email" maxlength="254" inputmode="email" autocomplete="email" required value="<?= h($emailValue) ?>">
          </div>
          <button class="btn btn-primary btn-lg btn-block" type="submit">Send reset link</button>
        </form>
        <p class="text-sm text-center auth-switch">Remembered it? <a href="login.php">Back to sign in</a></p>

      <?php elseif ($authMode === 'reset'): ?>
        <form method="post" action="reset-password.php" novalidate>
          <input type="hidden" name="_csrf" value="<?= h($csrfToken) ?>">
          <input type="hidden" name="t" value="<?= h($resetToken) ?>">
          <?php if ($emailValue !== ''): ?>
            <input type="email" name="username" value="<?= h($emailValue) ?>" autocomplete="username" hidden>
            <p class="text-sm text-muted">Account: <strong><?= h($emailValue) ?></strong></p>
          <?php endif; ?>
          <?= $passwordFields($isInvite ? 'Password' : 'New password') ?>
          <button class="btn btn-primary btn-lg btn-block" type="submit"><?= $isInvite ? 'Activate my account' : 'Save new password' ?></button>
        </form>

      <?php elseif ($authMode === 'verify'): ?>
        <form method="post" action="verify.php" novalidate>
          <input type="hidden" name="_csrf" value="<?= h($csrfToken) ?>">
          <input type="hidden" name="action" value="code">
          <div class="field">
            <label class="label" for="code">Verification code</label>
            <input class="input code-input" id="code" name="code" type="text" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" autocomplete="one-time-code" placeholder="000000" required>
          </div>
          <button class="btn btn-primary btn-lg btn-block" type="submit">Confirm email</button>
        </form>
        <form method="post" action="verify.php" class="text-center auth-switch">
          <input type="hidden" name="_csrf" value="<?= h($csrfToken) ?>">
          <input type="hidden" name="action" value="resend">
          <p class="text-sm text-faint">Didn't get it? Check spam, or
            <button class="btn-link" type="submit"<?= $resendWait > 0 ? ' disabled data-countdown="' . $resendWait . '"' : '' ?>>send a new code<?= $resendWait > 0 ? ' in ' . $resendWait . 's' : '' ?></button>
          </p>
        </form>
        <p class="text-sm text-center"><a href="dashboard.php">Do this later</a></p>

      <?php else: ?>
        <a class="btn btn-primary btn-lg btn-block" href="<?= h(is_string($doneUrl ?? null) ? $doneUrl : 'login.php') ?>"><?= h(is_string($doneLabel ?? null) ? $doneLabel : 'Continue to sign in') ?></a>
      <?php endif; ?>
    </section>
  </main>

  <footer class="site-footer">
    <div class="container flex-between flex-wrap text-xs text-faint">
      <span>© <?= date('Y') ?> YOLOTASK by Nifelux</span>
      <nav class="flex footer-links" aria-label="Legal"><a href="<?= h($mainUrl) ?>/terms.php">Terms</a><a href="<?= h($mainUrl) ?>/privacy.php">Privacy</a><a href="<?= h($mainUrl) ?>/refunds.php">Refunds</a></nav>
    </div>
  </footer>

  <nav class="bottom-nav" aria-label="<?= h($roleTitle) ?> mobile">
    <a href="index.php"><?= yolotask_nav_icon('home') ?><span>Home</span></a>
    <a href="login.php"<?= $authMode === 'login' ? ' class="active" aria-current="page"' : '' ?>><?= yolotask_nav_icon('login') ?><span>Sign in</span></a>
    <?php if ($isStaff): ?>
      <a href="forgot-password.php"<?= $authMode === 'forgot' ? ' class="active" aria-current="page"' : '' ?>><?= yolotask_nav_icon('shield') ?><span>Recover</span></a>
    <?php else: ?>
      <a href="register.php"<?= $authMode === 'register' ? ' class="active" aria-current="page"' : '' ?>><?= yolotask_nav_icon('user-plus') ?><span>Join</span></a>
    <?php endif; ?>
    <a href="<?= h($mainUrl) ?>/terms.php"><?= yolotask_nav_icon('doc') ?><span>Terms</span></a>
  </nav>
</body>
</html>
