<?php
declare(strict_types=1);

use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Growth\Levels;

require_once __DIR__ . '/levels-helpers.php';

/*
 * Public earner levels page on the main site (Phase 7). Lists the active
 * levels and their benefits straight from earner_levels, so admin edits show
 * up immediately. No personal data: nothing here reads the session user.
 */
if (yolotask_role() !== 'main') yolotask_error_page(404);
$levels = [];
$enabled = true;
$defaultHeld = 3;
$failed = false;
try {
    $pdo = Database::connection();
    $enabled = Levels::enabled($pdo);
    $levels = $enabled ? Levels::all($pdo, true) : [];
    $defaultHeld = max(1, \YoloTask\Core\Settings::int($pdo, 'tasks.max_active_reservations'));
} catch (Throwable $e) {
    Logger::error('public.levels_failed', ['exception' => get_class($e)]);
    $failed = true;
}
$earnerUrl = yolotask_portal_url('earner');
$docTitle = 'Earner levels';
$docEyebrow = 'YOLOTASK LEVELS';
$docUpdated = 'Levels and benefits as set by YOLOTASK today.';
ob_start();
?>
<p>Every earner on YOLOTASK has a level. Your level grows with your approved tasks and your approval rate (approved ÷ approved + rejected), and each level decides how many tasks you can hold at once and whether premium tasks, which pay more, are open to you.</p>
<?php if ($failed): ?>
  <p class="text-faint">Levels could not be loaded right now. Please check back in a moment.</p>
<?php elseif (!$enabled || $levels === []): ?>
  <p>Levels are paused right now: every earner can hold up to <?= (int) $defaultHeld ?> tasks at once and premium tasks are open to everyone with matching interests.</p>
<?php else: ?>
  <h2>Levels and benefits</h2>
  <?= yolotask_levels_list($levels) ?>
<?php endif; ?>
<h2>Good to know</h2>
<ul>
  <li>Your level updates on its own as tasks are approved or rejected. A low approval rate can move you down a level.</li>
  <li>Premium tasks also need at least one interest that matches the campaign; you pick your interests after signing up.</li>
  <li>YOLOTASK pays for real, verified work only. Fake proofs lead to rejection and account suspension.</li>
</ul>
<p><a class="btn btn-primary" href="<?= h($earnerUrl) ?>/register.php">Start earning</a> <a class="btn btn-ghost" href="<?= h($earnerUrl) ?>/login.php">Sign in</a></p>
<?php
$docBody = (string) ob_get_clean();
require YOLOTASK_APP_ROOT . '/shared/views/layouts/document.php';
