<?php
declare(strict_types=1);

/**
 * Authenticated shell contract:
 * - The route must authenticate and authorize before including this view.
 * - $contentHtml must contain trusted, server-rendered application markup.
 * - $navItems is an array of ['label' => string, 'url' => string].
 */
$appName = is_string($appName ?? null) ? $appName : 'YOLOTASK V2';
$roleTitle = is_string($roleTitle ?? null) ? $roleTitle : 'Workspace';
$pageTitle = is_string($pageTitle ?? null) ? $pageTitle : $roleTitle;
$userDisplayName = is_string($userDisplayName ?? null) ? $userDisplayName : '';
$contentHtml = is_string($contentHtml ?? null) ? $contentHtml : '';
$navItems = is_array($navItems ?? null) ? $navItems : [];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#102b46">
  <title><?= h($pageTitle) ?> · <?= h($appName) ?></title>
  <link rel="stylesheet" href="assets/css/app.css">
  <script src="assets/js/ui.js" defer></script>
</head>
<body class="app-body role-<?= h((string) (defined('YOLOTASK_APP_ROLE') ? YOLOTASK_APP_ROLE : '')) ?>">
  <a class="skip-link" href="#app-content">Skip to workspace</a>
  <header class="topbar"><div class="topbar-inner">
    <a class="brand" href="./" aria-label="<?= h($appName) ?> home"><span class="brand-mark" aria-hidden="true">Y</span><span><?= h($appName) ?></span></a>
    <div class="topbar-actions"><span class="glass-badge badge-role"><?= h($roleTitle) ?></span><?php if ($userDisplayName !== ''): ?><span class="user-chip glass-chip"><?= h($userDisplayName) ?></span><?php endif; ?></div>
  </div></header>
  <main id="app-content" class="page-shell app-main">
    <header class="workspace-heading"><p class="eyebrow"><?= h(strtoupper($roleTitle)) ?> WORKSPACE</p><h1><?= h($pageTitle) ?></h1></header>
    <?= $contentHtml ?>
  </main>
  <nav class="mobile-nav" aria-label="Workspace navigation">
    <?php foreach ($navItems as $item): ?><a href="<?= h((string) ($item['url'] ?? '#')) ?>"><span class="nav-dot" aria-hidden="true"></span><span><?= h((string) ($item['label'] ?? 'Section')) ?></span></a><?php endforeach; ?>
  </nav>
  <footer class="site-footer"><a class="footer-brand" href="./"><span class="brand-mark brand-mark-small" aria-hidden="true">Y</span><span><?= h($appName) ?></span></a><p><?= h($roleTitle) ?> workspace</p><span class="footer-status"><span class="status-indicator" aria-hidden="true"></span>Account session active</span></footer>
</body>
</html>
