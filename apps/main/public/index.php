<?php
declare(strict_types=1);

define('YOLOTASK_APP_ROLE', 'main');
define('YOLOTASK_APP_ROOT', dirname(__DIR__, 3));
require_once YOLOTASK_APP_ROOT . '/shared/bootstrap.php';

$roleTitle = 'YOLOTASK';
$subtitle = 'A task and promotion marketplace';
$description = 'Explore how earners and advertisers use YOLOTASK.';
$appName = (string) (yolotask_config()['app_name'] ?? 'YOLOTASK V2');
$links = ['Dashboard' => '#dashboard', 'Tasks' => '#tasks', 'Wallet' => '#wallet', 'Profile' => '#profile'];
if (YOLOTASK_APP_ROLE === 'main') $links = ['Home'=>'#home','How it works'=>'#how','Sign in'=>'#login'];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#102b46">
  <meta name="description" content="<?= h($subtitle) ?>">
  <title><?= h($roleTitle) ?> · <?= h($appName) ?></title>
  <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
  <a class="skip-link" href="#main-content">Skip to content</a>
  <header class="topbar">
    <a class="brand" href="./" aria-label="<?= h($appName) ?> home"><span class="brand-mark" aria-hidden="true">Y</span><span><?= h($appName) ?></span></a>
    <span class="role-pill"><?= h($roleTitle) ?></span>
  </header>
  <main id="main-content" class="page-shell">
    <section class="welcome-card" id="dashboard" aria-labelledby="welcome-title">
      <p class="eyebrow">MOBILE-FIRST STARTER</p>
      <h1 id="welcome-title"><?= h($subtitle) ?></h1>
      <p class="lead"><?= h($description) ?></p>
      <div class="status-note" role="status"><strong>Setup stage</strong><span>This responsive app shell is live. Product features and database migrations are still under development.</span></div>
      <a class="primary-button" href="#next-steps">View next steps</a>
    </section>
    <section class="content-grid" aria-label="Project status">
      <article class="info-card"><span class="icon-badge" aria-hidden="true">01</span><h2>Private by design</h2><p>Configuration, shared PHP source, migrations, cron scripts, and uploaded files belong outside public document roots.</p></article>
      <article class="info-card"><span class="icon-badge" aria-hidden="true">02</span><h2>Financial features disabled</h2><p>No payment, wallet, withdrawal, or balance-changing operation is implemented in this package.</p></article>
      <article class="info-card"><span class="icon-badge" aria-hidden="true">03</span><h2>Next implementation phase</h2><p>Approve V1 feature parity and product decisions before enabling authentication, ledger, payments, or migration.</p></article>
    </section>
    <section id="next-steps" class="next-steps">
      <p class="eyebrow">REPOSITORY STATUS</p><h2>Ready for Codespaces review</h2>
      <p>Use the included import guide, create a private feature branch, and review the scaffold before any cPanel staging deployment.</p>
      <a class="text-link" href="https://github.com">Open GitHub</a>
    </section>
  </main>
  <nav class="mobile-nav" aria-label="Primary navigation">
    <?php foreach ($links as $label => $href): ?><a href="<?= h($href) ?>"><span class="nav-dot" aria-hidden="true"></span><span><?= h($label) ?></span></a><?php endforeach; ?>
  </nav>
  <footer class="site-footer"><span>YOLOTASK V2 · Starter scaffold</span><span>Not for live financial use</span></footer>
</body>
</html>
