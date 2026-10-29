<?php

declare(strict_types=1);

/**
 * YOLOTASK V2 — Authenticated app shell.
 * The route has ALREADY authenticated the user before including this layout.
 *
 * Expected variables:
 *   $appName, $roleTitle, $pageTitle, $userDisplayName, $navItems,
 *   $contentHtml (trusted server HTML, echoed raw), $logoutCsrfToken,
 *   $flash (?string), $flashType ('success'|'error'|'warning')
 * Uses global helper h(string): string for escaping.
 */

$appName         = yolotask_brand_name(is_string($appName ?? null) ? $appName : null);
$roleTitle       = is_string($roleTitle ?? null) && $roleTitle !== '' ? $roleTitle : '';
$pageTitle       = is_string($pageTitle ?? null) && $pageTitle !== '' ? $pageTitle : 'Dashboard';
$userDisplayName = is_string($userDisplayName ?? null) && $userDisplayName !== '' ? $userDisplayName : 'Account';
$navItems        = array_values(array_filter(
    is_array($navItems ?? null) ? $navItems : [],
    static function ($item): bool {
        return is_array($item)
            && isset($item['label'], $item['url'], $item['icon'])
            && is_string($item['label']) && $item['label'] !== ''
            && is_string($item['url']) && $item['url'] !== ''
            && is_string($item['icon']) && $item['icon'] !== '';
    }
));
$contentHtml     = is_string($contentHtml ?? null) ? $contentHtml : '';
$logoutCsrf      = is_string($logoutCsrfToken ?? null) ? $logoutCsrfToken : '';
$flash           = is_string($flash ?? null) && $flash !== '' ? $flash : null;
$flashType       = in_array($flashType ?? null, ['success', 'error', 'warning'], true) ? $flashType : 'success';

/** Inline SVG path ids available to nav items. */
function yolotask_nav_icon(string $id): string
{
    static $paths = [
        'home'      => '<path d="m3 10.5 9-7.5 9 7.5V20a1.5 1.5 0 0 1-1.5 1.5h-4.5v-6h-6v6H4.5A1.5 1.5 0 0 1 3 20z"/>',
        'tasks'     => '<path d="M4 7l2 2 4-4M4 17l2 2 4-4M13 7h7M13 17h7"/>',
        'wallet'    => '<path d="M3 8a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2zM16 12h3M3 9h18"/>',
        'user'      => '<path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM5 20.5a7.5 7.5 0 0 1 14 0"/>',
        'chart'     => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        'megaphone' => '<path d="m3 11 14-6v14L3 13zM7 12v6a2 2 0 0 0 4 0M19 9a3 3 0 0 1 0 6"/>',
        'bell'      => '<path d="M6 9a6 6 0 0 1 12 0c0 5 2 6 2 6H4s2-1 2-6M10 20a2 2 0 0 0 4 0"/>',
        'inbox'     => '<path d="M3 13h5l1.5 2.5h5L16 13h5M4 6h16a1 1 0 0 1 1 1v11a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1z"/>',
        'shield'    => '<path d="M12 3 4.5 6v6c0 4.5 3 7.5 7.5 9 4.5-1.5 7.5-4.5 7.5-9V6zM9 12l2 2 4-4"/>',
    ];

    $path = $paths[$id] ?? $paths['home'];

    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
        . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>';
}

$flashIcon = [
    'success' => '<path d="M20 6 9 17l-5-5"/>',
    'error'   => '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6M9 9l6 6"/>',
    'warning' => '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4M12 17h.01"/>',
][$flashType];
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<title><?= h($pageTitle) ?> &middot; <?= h($appName) ?><?= $roleTitle !== '' ? ' ' . h($roleTitle) : '' ?></title>
<meta name="theme-color" content="#0A1628">
<script>
(function () {
  try {
    var t = localStorage.getItem('yolo_theme');
    if (t !== 'light' && t !== 'dark') {
      t = 'dark';
    }
    document.documentElement.setAttribute('data-theme', t);
  } catch (e) { /* keep default dark */ }
})();
</script>
<link rel="stylesheet" href="<?= h(yolotask_asset('assets/css/app.css')) ?>">
<script src="<?= h(yolotask_asset('assets/js/ui.js')) ?>" defer></script>
<style>
  .safe-area { padding-bottom: env(safe-area-inset-bottom, 0); }
  .bottom-nav { margin-bottom: env(safe-area-inset-bottom, 0); }
  .skip-link {
    position: absolute;
    left: -9999px;
    z-index: 300;
    padding: 0.5rem 1rem;
    background: var(--primary);
    color: #05121F;
    font-weight: 600;
    border-radius: var(--radius-sm);
  }
  .skip-link:focus { left: var(--space-4); top: var(--space-4); }
  .user-chip {
    display: inline-flex;
    align-items: center;
    gap: var(--space-2);
    padding: 0.375rem 0.75rem;
    background: var(--primary-soft);
    color: var(--primary);
    border-radius: var(--radius-pill);
    font-size: var(--text-sm);
    font-weight: 600;
    max-width: 180px;
  }
  .user-chip span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .site-footer { padding: var(--space-5) 0; border-top: 1px solid var(--border); margin-top: var(--space-6); }
  @media (min-width: 1025px) { .bottom-nav { display: flex; } }
</style>
</head>
<body>

<a class="skip-link" href="#main">Skip to content</a>

<header class="site-header">
  <div class="container header-inner">
    <a class="brand" href="<?= h($navItems[0]['url'] ?? 'index.php') ?>">
      <span class="brand-mark" aria-hidden="true">Y</span>
      <span><?= h($appName) ?></span>
    </a>

    <nav class="header-nav" aria-label="Primary">
      <?php foreach ($navItems as $item): ?>
        <a href="<?= h($item['url']) ?>"><?= h($item['label']) ?></a>
      <?php endforeach; ?>
    </nav>

    <div class="header-actions">
      <button type="button" class="theme-toggle" data-theme-toggle aria-label="Toggle light and dark theme">
        <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M4.93 4.93l1.41 1.41m11.32 11.32 1.41 1.41M2 12h2m16 0h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg>
        <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
      </button>

      <span class="user-chip" title="<?= h($userDisplayName) ?>">
        <?= yolotask_nav_icon('user') ?>
        <span><?= h($userDisplayName) ?></span>
      </span>

      <form method="post" action="logout.php">
        <input type="hidden" name="_csrf" value="<?= h($logoutCsrf) ?>">
        <button type="submit" class="btn btn-ghost btn-sm">Sign out</button>
      </form>
    </div>
  </div>
</header>

<?php if ($flash !== null): ?>
  <div class="toast-container" aria-live="polite">
    <div class="toast toast-<?= h($flashType) ?>" data-auto="4500" role="<?= $flashType === 'error' ? 'alert' : 'status' ?>">
      <span class="toast-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $flashIcon ?></svg>
      </span>
      <div class="toast-body">
        <div class="toast-msg"><?= h($flash) ?></div>
      </div>
      <button type="button" class="toast-close" data-toast-dismiss aria-label="Dismiss notification">
        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
      </button>
    </div>
  </div>
<?php endif; ?>

<main id="main" class="container page">
  <div class="page-header">
    <h1><?= h($pageTitle) ?></h1>
  </div>

  <?= $contentHtml ?>
</main>

<footer class="site-footer">
  <div class="container flex-between flex-wrap">
    <p class="text-sm text-muted">&copy; <?= date('Y') ?> YOLOTASK by Nifelux.</p>
    <p class="text-sm text-faint"><?= h($appName) ?><?= $roleTitle !== '' ? ' &middot; ' . h($roleTitle) : '' ?></p>
  </div>
</footer>

<nav class="bottom-nav safe-area" aria-label="Primary">
  <?php foreach ($navItems as $item): ?>
    <a href="<?= h($item['url']) ?>">
      <?= yolotask_nav_icon($item['icon']) ?>
      <span><?= h($item['label']) ?></span>
    </a>
  <?php endforeach; ?>
</nav>

</body>
</html>
