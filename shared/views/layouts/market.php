<?php
declare(strict_types=1);

/**
 * Public marketplace layout (main site: product page, order / delivery page).
 * Expects $mktTitle, trusted $mktBody HTML, optional $mktDescription,
 * $mktImage (absolute URL for link previews), $mktFlash/$mktFlashType, $mktNoIndex.
 */
$appName = yolotask_brand_name();
$mktTitle = is_string($mktTitle ?? null) ? $mktTitle : 'YOLOTASK Marketplace';
$mktBody = is_string($mktBody ?? null) ? $mktBody : '';
$mktDescription = is_string($mktDescription ?? null) ? $mktDescription : 'Digital products on the YOLOTASK marketplace.';
$mktImage = is_string($mktImage ?? null) ? $mktImage : '';
$mktFlash = is_string($mktFlash ?? null) && $mktFlash !== '' ? $mktFlash : null;
$mktFlashType = in_array($mktFlashType ?? null, ['success', 'error', 'warning'], true) ? $mktFlashType : 'success';
$earnerUrl = yolotask_portal_url('earner');
?>
<!doctype html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#0A1628">
  <?php if (!empty($mktNoIndex)): ?><meta name="robots" content="noindex"><?php endif; ?>
  <title><?= h($mktTitle) ?> · <?= h($appName) ?></title>
  <meta name="description" content="<?= h(mb_strimwidth($mktDescription, 0, 160, '…')) ?>">
  <meta property="og:title" content="<?= h($mktTitle) ?>">
  <meta property="og:description" content="<?= h(mb_strimwidth($mktDescription, 0, 200, '…')) ?>">
  <?php if ($mktImage !== ''): ?><meta property="og:image" content="<?= h($mktImage) ?>"><?php endif; ?>
  <script>(function(){try{var t=localStorage.getItem('yolo_theme');if(t!=='light'&&t!=='dark'){t='dark';}document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>
  <link rel="stylesheet" href="<?= h(yolotask_asset('assets/css/app.css')) ?>">
  <script src="<?= h(yolotask_asset('assets/js/ui.js')) ?>" defer></script>
</head>
<body>
  <a class="skip-link" href="#main">Skip to content</a>
  <header class="site-header">
    <div class="container header-inner">
      <a class="brand" href="<?= h(yolotask_portal_url('main')) ?>/"><span class="brand-mark" aria-hidden="true">Y</span><span><?= h($appName) ?></span></a>
      <div class="header-actions">
        <button type="button" class="theme-toggle" data-theme-toggle aria-label="Toggle light and dark theme">
          <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M4.93 4.93l1.41 1.41m11.32 11.32 1.41 1.41M2 12h2m16 0h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg>
          <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
        </button>
        <a class="btn btn-ghost btn-sm" href="<?= h($earnerUrl) ?>/register.php">Earn with YOLOTASK</a>
      </div>
    </div>
  </header>
  <?php if ($mktFlash !== null): ?>
    <div class="toast-container" aria-live="polite"><div class="toast toast-<?= h($mktFlashType) ?>" data-auto="6000" role="<?= $mktFlashType === 'error' ? 'alert' : 'status' ?>"><div class="toast-body"><div class="toast-msg"><?= h($mktFlash) ?></div></div>
      <button type="button" class="toast-close" data-toast-dismiss aria-label="Dismiss notification">×</button></div></div>
  <?php endif; ?>
  <main id="main" class="container page">
    <?= $mktBody ?>
  </main>
  <footer class="site-footer">
    <div class="container flex-between flex-wrap">
      <p class="text-sm">&copy; <?= date('Y') ?> YOLOTASK by Nifelux. Secure checkout.</p>
      <nav class="footer-links flex" aria-label="Legal links"><a href="terms.php">Terms</a><a href="privacy.php">Privacy</a><a href="refunds.php">Refunds</a></nav>
    </div>
  </footer>
</body>
</html>
