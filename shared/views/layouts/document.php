<?php
declare(strict_types=1);

/**
 * Long-form document layout (Terms, Privacy, Refunds).
 * Expects $docTitle, $docUpdated and trusted $docBody HTML from the page.
 */
$appName = (string) (yolotask_config()['app_name'] ?? 'YOLOTASK');
$docTitle = is_string($docTitle ?? null) ? $docTitle : 'YOLOTASK';
$docUpdated = is_string($docUpdated ?? null) ? $docUpdated : '';
$docBody = is_string($docBody ?? null) ? $docBody : '';
$earnerUrl = yolotask_portal_url('earner');
$legalLinks = ['terms.php' => 'Terms', 'privacy.php' => 'Privacy', 'refunds.php' => 'Refunds'];
?>
<!doctype html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#0A1628">
  <meta name="description" content="<?= h($docTitle) ?> for YOLOTASK, the verified digital task marketplace.">
  <title><?= h($docTitle) ?> · <?= h($appName) ?></title>
  <script>(function(){try{var t=localStorage.getItem('yolo_theme');if(t!=='light'&&t!=='dark'){t=window.matchMedia('(prefers-color-scheme: light)').matches?'light':'dark';}document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>
  <link rel="stylesheet" href="assets/css/app.css">
  <script src="assets/js/ui.js" defer></script>
</head>
<body>
  <a class="skip-link" href="#main">Skip to content</a>
  <header class="site-header">
    <div class="container header-inner">
      <a class="brand" href="index.php"><span class="brand-mark" aria-hidden="true">Y</span><span><?= h($appName) ?></span></a>
      <nav class="header-nav" aria-label="Legal">
        <?php foreach ($legalLinks as $url => $label): ?><a href="<?= h($url) ?>"><?= h($label) ?></a><?php endforeach; ?>
      </nav>
      <div class="header-actions">
        <button type="button" class="theme-toggle" data-theme-toggle aria-label="Toggle light and dark theme">
          <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M4.93 4.93l1.41 1.41m11.32 11.32 1.41 1.41M2 12h2m16 0h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg>
          <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
        </button>
        <a class="btn btn-primary btn-sm" href="<?= h($earnerUrl) ?>/login.php">Sign in</a>
      </div>
    </div>
  </header>

  <main id="main" class="container page">
    <article class="card legal-doc">
      <p class="eyebrow text-xs fw-bold text-primary">YOLOTASK POLICY</p>
      <h1><?= h($docTitle) ?></h1>
      <?php if ($docUpdated !== ''): ?><p class="text-sm text-faint"><?= h($docUpdated) ?></p><?php endif; ?>
      <?= $docBody ?>
    </article>
  </main>

  <footer class="site-footer">
    <div class="container flex-between flex-wrap">
      <p class="text-sm">&copy; <?= date('Y') ?> YOLOTASK by Nifelux. All rights reserved.</p>
      <nav class="footer-links flex" aria-label="Legal links">
        <?php foreach ($legalLinks as $url => $label): ?><a href="<?= h($url) ?>"><?= h($label) ?></a><?php endforeach; ?>
      </nav>
    </div>
  </footer>

  <nav class="bottom-nav" aria-label="Legal mobile">
    <a href="index.php">Home</a>
    <?php foreach ($legalLinks as $url => $label): ?><a href="<?= h($url) ?>"><?= h($label) ?></a><?php endforeach; ?>
  </nav>
</body>
</html>
