<?php
declare(strict_types=1);

$appName = is_string($appName ?? null) ? $appName : 'YOLOTASK V2';
$roleTitle = is_string($roleTitle ?? null) ? $roleTitle : 'YOLOTASK';
$roleSlug = is_string($roleSlug ?? null) ? $roleSlug : 'main';
$subtitle = is_string($subtitle ?? null) ? $subtitle : 'A marketplace for clear, verifiable work.';
$description = is_string($description ?? null) ? $description : '';
$heroChips = is_array($heroChips ?? null) ? $heroChips : [];
$features = is_array($features ?? null) ? $features : [];
$navItems = is_array($navItems ?? null) ? $navItems : [];
$headerAction = is_array($headerAction ?? null) ? $headerAction : null;
$primaryAction = is_array($primaryAction ?? null) ? $primaryAction : null;
$secondaryAction = is_array($secondaryAction ?? null) ? $secondaryAction : null;
$signedInUser = is_array($signedInUser ?? null) ? $signedInUser : null;
$logoutCsrfToken = is_string($logoutCsrfToken ?? null) ? $logoutCsrfToken : '';
$statusMessage = is_string($statusMessage ?? null) ? $statusMessage : 'This staging build is for testing only. Marketplace and financial activity are disabled.';
$availabilityTitle = is_string($availabilityTitle ?? null) ? $availabilityTitle : 'What is available in this build';
$availabilityBody = is_string($availabilityBody ?? null) ? $availabilityBody : 'Authentication is available for selected roles. Marketplace workflows remain under development.';
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
  <script src="assets/js/ui.js" defer></script>
</head>
<body class="portal-body role-<?= h($roleSlug) ?>">
  <a class="skip-link" href="#main-content">Skip to content</a>
  <header class="topbar">
    <div class="topbar-inner">
      <a class="brand" href="./" aria-label="<?= h($appName) ?> home"><span class="brand-mark" aria-hidden="true">Y</span><span><?= h($appName) ?></span></a>
      <div class="topbar-actions">
        <span class="glass-badge badge-role"><?= h($roleTitle) ?></span>
        <?php if ($signedInUser !== null): ?>
          <span class="glass-chip user-chip"><?= h('Hi, ' . (string) ($signedInUser['display_name'] ?? 'there')) ?></span>
          <form class="header-logout" method="post" action="logout.php"><input type="hidden" name="_csrf" value="<?= h($logoutCsrfToken) ?>"><button class="header-action" type="submit">Sign out</button></form>
        <?php elseif ($headerAction !== null): ?><a class="header-action" href="<?= h((string) ($headerAction['url'] ?? '#')) ?>"><?= h((string) ($headerAction['label'] ?? 'Open')) ?></a><?php endif; ?>
      </div>
    </div>
  </header>

  <main id="main-content" class="page-shell">
    <section class="welcome-card" id="overview" aria-labelledby="welcome-title">
      <div class="hero-orbit hero-orbit-one" aria-hidden="true"></div>
      <div class="hero-orbit hero-orbit-two" aria-hidden="true"></div>
      <div class="hero-content">
        <div class="chip-row hero-chips" aria-label="Portal details">
          <?php foreach ($heroChips as $chip): ?><span class="glass-chip"><?= h((string) $chip) ?></span><?php endforeach; ?>
        </div>
        <p class="eyebrow">A CLEARER WAY TO WORK ONLINE</p>
        <h1 id="welcome-title"><?= h($subtitle) ?></h1>
        <p class="lead"><?= h($description) ?></p>
        <div class="hero-actions">
          <?php if ($primaryAction !== null): ?><a class="primary-button" href="<?= h((string) ($primaryAction['url'] ?? '#')) ?>"><?= h((string) ($primaryAction['label'] ?? 'Continue')) ?><span aria-hidden="true">↗</span></a><?php endif; ?>
          <?php if ($secondaryAction !== null): ?><a class="secondary-button" href="<?= h((string) ($secondaryAction['url'] ?? '#')) ?>"><?= h((string) ($secondaryAction['label'] ?? 'Learn more')) ?></a><?php endif; ?>
          <button class="quiet-button" type="button" data-modal-open="availability-dialog" aria-haspopup="dialog" aria-controls="availability-dialog">What is live?</button>
        </div>
      </div>
      <aside class="hero-panel glass-panel" aria-label="Release status">
        <span class="status-indicator" aria-hidden="true"></span>
        <p class="panel-kicker">V2 BUILD STATUS</p>
        <p class="panel-title">A careful start, built on fresh foundations.</p>
        <div class="panel-rule"></div>
        <p class="panel-copy">Role-based sign-in is being validated before marketplace features are enabled.</p>
        <div class="chip-row"><span class="glass-chip chip-mint">Secure sessions</span><span class="glass-chip">Fresh V2 data</span></div>
      </aside>
    </section>

    <section class="status-area" aria-label="Staging notice">
      <div class="glass-toast toast-info" role="status" aria-live="polite">
        <span class="toast-icon" aria-hidden="true">i</span>
        <div><strong>Staging environment</strong><p><?= h($statusMessage) ?></p></div>
        <button class="toast-dismiss" type="button" data-toast-dismiss aria-label="Dismiss staging notice">×</button>
      </div>
    </section>

    <section class="section-heading" id="value" aria-labelledby="value-title">
      <div><p class="eyebrow">DESIGNED AROUND TRUST</p><h2 id="value-title">A solid foundation before the full marketplace.</h2></div>
      <span class="glass-badge badge-subtle">BUILDING IN STAGES</span>
    </section>
    <section class="content-grid" aria-label="Portal information">
      <?php foreach ($features as $index => $feature): ?>
        <article class="glass-card info-card">
          <div class="card-topline"><span class="icon-badge" aria-hidden="true"><?= h((string) ($feature['icon'] ?? sprintf('%02d', $index + 1))) ?></span><span class="glass-badge badge-subtle"><?= h((string) ($feature['tag'] ?? 'IN PROGRESS')) ?></span></div>
          <h3><?= h((string) ($feature['title'] ?? '')) ?></h3>
          <p><?= h((string) ($feature['description'] ?? '')) ?></p>
        </article>
      <?php endforeach; ?>
    </section>

    <section class="next-steps glass-panel" id="access" aria-labelledby="access-title">
      <div><p class="eyebrow">YOUR ROLE, YOUR SPACE</p><h2 id="access-title">Separate portals. A shared standard of care.</h2><p>Each YOLOTASK role has its own entry point and session boundary. Staff access is provisioned by an authorized administrator.</p></div>
      <button class="secondary-button" type="button" data-modal-open="availability-dialog" aria-haspopup="dialog" aria-controls="availability-dialog">Review this staging build</button>
    </section>
  </main>

  <nav class="mobile-nav" aria-label="Page navigation">
    <?php foreach ($navItems as $item): ?><a href="<?= h((string) ($item['url'] ?? '#overview')) ?>"><span class="nav-dot" aria-hidden="true"></span><span><?= h((string) ($item['label'] ?? 'Section')) ?></span></a><?php endforeach; ?>
  </nav>
  <footer class="site-footer">
    <a class="footer-brand" href="./"><span class="brand-mark brand-mark-small" aria-hidden="true">Y</span><span><?= h($appName) ?></span></a>
    <p>Built for clearer work, fair review, and careful growth.</p>
    <span class="footer-status"><span class="status-indicator" aria-hidden="true"></span>Marketplace features are not yet active</span>
  </footer>

  <dialog class="glass-modal" id="availability-dialog" aria-labelledby="availability-title">
    <div class="modal-head"><span class="glass-badge badge-role">STAGING NOTES</span><button class="icon-button" type="button" data-modal-close aria-label="Close dialog">×</button></div>
    <h2 id="availability-title"><?= h($availabilityTitle) ?></h2>
    <p><?= h($availabilityBody) ?></p>
    <ul class="modal-list"><li>Registration and sign-in are being tested for earners and advertisers.</li><li>Reviewer and admin access remains staff-controlled.</li><li>Tasks, campaigns, balances, payments, and withdrawals are not enabled.</li></ul>
    <button class="primary-button modal-done" type="button" data-modal-close>Understood</button>
  </dialog>
</body>
</html>
