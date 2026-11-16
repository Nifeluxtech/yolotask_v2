<?php
declare(strict_types=1);

/**
 * Maintenance page (HTTP 503), rendered by YoloTask\Core\Maintenance::respond().
 * Expects $maintenanceMessage (string) and $maintenanceUntil (?DateTimeImmutable, Lagos).
 * Self-contained: no links into gated pages, no session, no database.
 */
$esc = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$message = is_string($maintenanceMessage ?? null) && trim($maintenanceMessage) !== '' ? trim($maintenanceMessage) : \YoloTask\Core\Maintenance::DEFAULT_MESSAGE;
$until = ($maintenanceUntil ?? null) instanceof DateTimeImmutable ? $maintenanceUntil : null;
$now = \YoloTask\Core\Clock::now();
$etaLine = null;
if ($until !== null) {
    $lagos = $until->setTimezone(new DateTimeZone('Africa/Lagos'));
    $today = $now->setTimezone(new DateTimeZone('Africa/Lagos'))->format('Y-m-d');
    $when = $lagos->format('Y-m-d') === $today ? $lagos->format('g:i A') . ' today' : $lagos->format('g:i A \o\n D j M');
    $etaLine = $until > $now
        ? 'We expect to be back by ' . $when . ' (Lagos time).'
        : 'This is taking a little longer than planned. Thank you for your patience.';
}
$css = function_exists('yolotask_asset') ? yolotask_asset('assets/css/app.css') : 'assets/css/app.css';
$js = function_exists('yolotask_asset') ? yolotask_asset('assets/js/ui.js') : 'assets/js/ui.js';
?>
<!doctype html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#0A1628">
  <meta name="robots" content="noindex">
  <title>Under maintenance · YOLOTASK</title>
  <script>(function(){try{var t=localStorage.getItem('yolo_theme');if(t==='light'||t==='dark'){document.documentElement.setAttribute('data-theme',t);}}catch(e){}})();</script>
  <link rel="stylesheet" href="/<?= $esc($css) ?>">
  <script src="/<?= $esc($js) ?>" defer></script>
  <style>
    .maintenance-card { gap: var(--space-4); }
    .maintenance-top { width: 100%; display: flex; align-items: center; justify-content: space-between; gap: var(--space-3); }
    .maintenance-icon { width: 56px; height: 56px; display: grid; place-items: center; border-radius: 50%; background: var(--primary-soft); color: var(--primary); }
    .maintenance-icon svg { width: 28px; height: 28px; }
    .maintenance-eta { padding: var(--space-2) var(--space-3); border-radius: var(--radius-pill); background: var(--primary-soft); color: var(--primary); font-weight: 600; }
    .maintenance-message { white-space: pre-line; overflow-wrap: anywhere; }
  </style>
</head>
<body class="error-page">
  <main id="main" class="container page error-main">
    <section class="card card-glow text-center error-card maintenance-card" role="status" aria-live="polite">
      <div class="maintenance-top">
        <span class="brand"><span class="brand-mark" aria-hidden="true">Y</span><span>YOLOTASK</span></span>
        <button type="button" class="theme-toggle" data-theme-toggle aria-label="Toggle light and dark theme">
          <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M4.93 4.93l1.41 1.41m11.32 11.32 1.41 1.41M2 12h2m16 0h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg>
          <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
        </button>
      </div>
      <span class="maintenance-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg></span>
      <p class="text-xs fw-bold text-primary">SCHEDULED MAINTENANCE</p>
      <h1>We'll be right back</h1>
      <p class="text-faint maintenance-message" data-maintenance-message><?= $esc($message) ?></p>
      <?php if ($etaLine !== null): ?>
        <p class="text-sm maintenance-eta" data-maintenance-eta><?= $esc($etaLine) ?></p>
      <?php endif; ?>
      <p class="text-sm text-faint">Your account, wallet balance and work are safe. Nothing is lost while we update.</p>
      <a class="btn btn-primary" href="">Try again</a>
    </section>
  </main>
</body>
</html>
