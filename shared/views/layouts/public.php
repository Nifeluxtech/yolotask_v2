<?php

declare(strict_types=1);

/**
 * YOLOTASK V2 — Public marketing layout.
 *
 * Expected (all optional) variables from the including view:
 *   $appName, $roleTitle, $roleSlug, $subtitle, $description, $heroChips,
 *   $features, $navItems, $headerAction, $primaryAction, $secondaryAction,
 *   $signedInUser, $logoutCsrfToken, $dashboardUrl, $legalBaseUrl
 * Uses global helper h(string): string for escaping.
 */

$appName        = yolotask_brand_name(is_string($appName ?? null) ? $appName : null);
$roleTitle      = is_string($roleTitle ?? null) && $roleTitle !== '' ? $roleTitle : '';
$roleSlug       = is_string($roleSlug ?? null) && $roleSlug !== '' ? $roleSlug : 'platform';
$subtitle       = is_string($subtitle ?? null) && $subtitle !== '' ? $subtitle : 'Earn on every task you complete';
$description    = is_string($description ?? null) && $description !== '' ? $description : 'A task platform that pays you for completed work, with transparent tracking and instant wallet updates.';
$heroChips      = array_values(array_filter(
    is_array($heroChips ?? null) ? $heroChips : [],
    static fn ($chip): bool => is_string($chip) && $chip !== ''
));
$features       = is_array($features ?? null) ? $features : [];
$navItems       = array_values(array_filter(
    is_array($navItems ?? null) ? $navItems : [],
    static function ($item): bool {
        return is_array($item)
            && isset($item['label'], $item['url'])
            && is_string($item['label']) && $item['label'] !== ''
            && is_string($item['url']) && $item['url'] !== '';
    }
));
$actions        = [];
foreach (['headerAction', 'primaryAction', 'secondaryAction'] as $actionKey) {
    $action = $$actionKey ?? null;
    if (is_array($action)
        && isset($action['url'], $action['label'])
        && is_string($action['url']) && $action['url'] !== ''
        && is_string($action['label']) && $action['label'] !== ''
    ) {
        $actions[$actionKey] = $action;
    } else {
        $actions[$actionKey] = null;
    }
}
$signedInUser   = is_array($signedInUser ?? null) && isset($signedInUser['display_name'])
    && is_string($signedInUser['display_name']) && $signedInUser['display_name'] !== ''
        ? $signedInUser : null;
$logoutCsrf     = is_string($logoutCsrfToken ?? null) ? $logoutCsrfToken : '';
$dashboardUrl   = is_string($dashboardUrl ?? null) && $dashboardUrl !== '' ? $dashboardUrl : null;
$legalBaseUrl   = is_string($legalBaseUrl ?? null) ? rtrim($legalBaseUrl, '/') : '';

$heroH1    = $roleTitle !== '' ? $roleTitle . ' — ' . $subtitle : $subtitle;
$featuresHeading = is_string($featuresHeading ?? null) && $featuresHeading !== '' ? $featuresHeading : 'Why YOLOTASK';
$steps = is_array($steps ?? null) && $steps !== [] ? array_values($steps) : [
    ['title' => 'Create your account', 'body' => 'Sign up in a minute with your name, email and a strong password.'],
    ['title' => 'Complete tasks', 'body' => 'Choose a task, follow the instructions exactly and submit your proof for review.'],
    ['title' => 'Get rewarded', 'body' => 'Approved proofs earn the reward shown on the task, recorded in your account history.'],
];
$legalUrl  = static fn (string $page): string => $legalBaseUrl . '/' . $page;
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($appName) ?><?= $roleTitle !== '' ? ' — ' . h($roleTitle) : '' ?></title>
<meta name="description" content="<?= h($description) ?>">
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
</head>
<body>

<a class="skip-link" href="#main">Skip to content</a>

<header class="site-header">
  <div class="container header-inner">
    <a class="brand" href="index.php">
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

      <?php if ($signedInUser !== null): ?>
        <?php if ($dashboardUrl !== null): ?>
          <a class="btn btn-primary btn-sm" href="<?= h($dashboardUrl) ?>">Go to dashboard</a>
        <?php endif; ?>
        <form method="post" action="logout.php" class="flex">
          <input type="hidden" name="_csrf" value="<?= h($logoutCsrf) ?>">
          <button type="submit" class="btn btn-ghost btn-sm">Sign out</button>
        </form>
      <?php elseif ($actions['headerAction'] !== null): ?>
        <a class="btn btn-primary btn-sm" href="<?= h($actions['headerAction']['url']) ?>"><?= h($actions['headerAction']['label']) ?></a>
      <?php endif; ?>
    </div>
  </div>
</header>

<main id="main" class="container page">

  <section class="hero" id="overview" data-role="<?= h($roleSlug) ?>">
    <div class="hero-orb hero-orb-1" aria-hidden="true"></div>
    <div class="hero-orb hero-orb-2" aria-hidden="true"></div>
    <?php if ($roleTitle !== '' && $roleSlug !== 'main'): ?><p class="hero-eyebrow"><?= h($roleTitle) ?> portal</p><?php endif; ?>
    <h1><?php
      // Signature V1 headline: the closing phrase glows in the cyan-to-violet gradient.
      $heroText  = trim($subtitle) !== '' ? trim($subtitle) : $heroH1;
      $heroParts = preg_split('/(?<=[.!?])\s+/', $heroText, -1, PREG_SPLIT_NO_EMPTY) ?: [$heroText];
      if (count($heroParts) >= 2) {
          $heroAccent = (string) array_pop($heroParts);
          $heroLead   = implode(' ', $heroParts);
      } else {
          $words      = preg_split('/\s+/', $heroText) ?: [$heroText];
          $heroAccent = implode(' ', array_splice($words, -min(2, count($words))));
          $heroLead   = implode(' ', $words);
      }
      echo h($heroLead), $heroLead !== '' ? ' ' : '', '<span class="grad">', h($heroAccent), '</span>';
    ?></h1>
    <p class="hero-lead"><?= h($description) ?></p>

    <?php if ($heroChips !== []): ?>
      <div class="hero-chips flex flex-wrap">
        <?php foreach ($heroChips as $chip): ?>
          <span class="badge badge-dot"><?= h($chip) ?></span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="hero-actions flex flex-wrap">
      <?php if ($signedInUser !== null && $dashboardUrl !== null): ?>
        <a class="btn btn-primary btn-lg" href="<?= h($dashboardUrl) ?>">Go to dashboard</a>
      <?php else: ?>
        <?php if ($actions['primaryAction'] !== null): ?>
          <a class="btn btn-primary btn-lg" href="<?= h($actions['primaryAction']['url']) ?>"><?= h($actions['primaryAction']['label']) ?></a>
        <?php endif; ?>
        <?php if ($actions['secondaryAction'] !== null): ?>
          <a class="btn btn-outline btn-lg" href="<?= h($actions['secondaryAction']['url']) ?>"><?= h($actions['secondaryAction']['label']) ?></a>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </section>

  <?php if ($features !== []): ?>
    <section id="features" aria-labelledby="features-heading">
      <div class="page-header">
        <h2 id="features-heading"><?= h($featuresHeading) ?></h2>
      </div>
      <div class="grid grid-3">
        <?php foreach ($features as $feature):
            $icon  = is_array($feature) && isset($feature['icon']) && is_string($feature['icon']) ? $feature['icon'] : '';
            $tag   = is_array($feature) && isset($feature['tag']) && is_string($feature['tag']) ? $feature['tag'] : '';
            $title = is_array($feature) && isset($feature['title']) && is_string($feature['title']) ? $feature['title'] : '';
            $desc  = is_array($feature) && isset($feature['description']) && is_string($feature['description']) ? $feature['description'] : '';
            if ($title === '' || $desc === '') { continue; }
        ?>
          <article class="card card-hover">
            <div class="stat-icon" aria-hidden="true"><?= h($icon) ?></div>
            <?php if ($tag !== ''): ?><span class="badge"><?= h($tag) ?></span><?php endif; ?>
            <h3><?= h($title) ?></h3>
            <p><?= h($desc) ?></p>
          </article>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <section class="trust-strip" id="how-it-works" aria-labelledby="how-heading">
    <div class="page-header">
      <h2 id="how-heading">How it works</h2>
    </div>
    <ol class="grid grid-3 steps">
      <?php foreach ($steps as $i => $step): ?>
      <li class="card">
        <span class="badge badge-dot">Step <?= (int) $i + 1 ?></span>
        <h3><?= h((string) ($step['title'] ?? '')) ?></h3>
        <p><?= h((string) ($step['body'] ?? '')) ?></p>
      </li>
      <?php endforeach; ?>
    </ol>
  </section>

</main>

<footer class="site-footer">
  <div class="container flex-between flex-wrap">
    <div>
      <a class="brand" href="index.php">
        <span class="brand-mark" aria-hidden="true">Y</span>
        <span><?= h($appName) ?></span>
      </a>
      <p class="text-sm">&copy; <?= date('Y') ?> YOLOTASK by Nifelux. All rights reserved.</p>
    </div>
    <nav class="footer-links flex" aria-label="Legal">
      <a href="<?= h($legalUrl('terms.php')) ?>">Terms</a>
      <a href="<?= h($legalUrl('privacy.php')) ?>">Privacy</a>
      <a href="<?= h($legalUrl('refunds.php')) ?>">Refunds</a>
    </nav>
  </div>
</footer>

<nav class="bottom-nav" aria-label="Primary mobile">
  <?php $dockIcons = ['home', 'sparkle', 'tasks']; ?>
  <?php foreach ($navItems as $i => $item): ?>
    <a href="<?= h($item['url']) ?>"<?= $i === 0 ? ' class="active"' : '' ?>><?= yolotask_nav_icon($item['icon'] ?? ($dockIcons[$i] ?? 'home')) ?><span><?= h($item['label']) ?></span></a>
  <?php endforeach; ?>
  <?php if ($signedInUser !== null && $dashboardUrl !== null): ?>
    <a href="<?= h($dashboardUrl) ?>"><?= yolotask_nav_icon('chart') ?><span>Dashboard</span></a>
  <?php elseif ($actions['headerAction'] !== null): ?>
    <a href="<?= h($actions['headerAction']['url']) ?>"><?= yolotask_nav_icon('login') ?><span><?= h($actions['headerAction']['label']) ?></span></a>
  <?php endif; ?>
</nav>

</body>
</html>
