<?php
declare(strict_types=1);

use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Core\Settings;
use YoloTask\Security\Csrf;

require_once __DIR__ . '/tasks-helpers.php';

/* Admin: task and campaign rules (admin-editable settings). */
if (yolotask_role() !== 'admin') yolotask_error_page(404);
yolotask_private_headers();
try {
    $pdo = Database::connection();
    $admin = yolotask_require_user($pdo);
} catch (Throwable $e) {
    Logger::error('admin.task_settings_load_failed', ['exception' => get_class($e)]);
    yolotask_error_page(500);
}

// key => [label, min, max, hint]
$fields = [
    'tasks.reservation_minutes' => ['Slot hold after an earner starts (minutes)', 5, 1440, 'Expired holds free the slot again.'],
    'tasks.max_active_reservations' => ['Tasks an earner can hold at once', 1, 20, 'Stops slot hoarding.'],
    'tasks.reward_hold_hours' => ['Reward hold after approval (hours)', 0, 720, '0 = rewards reach the earner wallet immediately.'],
    'tasks.resubmission_cap' => ['Resubmissions allowed after a rejection', 0, 5, ''],
    'tasks.stale_hours' => ['A pending proof is stale after (hours)', 1, 336, 'Stale proofs go to the reviewer queue and Submissions → Stale.'],
    'tasks.auto_approve_default_hours' => ['Default auto-approve window (hours)', 12, 336, 'Used until an advertiser chooses their own.'],
    'tasks.auto_approve_min_hours' => ['Shortest auto-approve window advertisers may pick (hours)', 1, 336, ''],
    'tasks.auto_approve_max_hours' => ['Longest auto-approve window advertisers may pick (hours)', 12, 720, ''],
    'campaigns.min_quantity' => ['Minimum campaign quantity', 1, 100000, ''],
    'campaigns.max_quantity' => ['Maximum campaign quantity', 1, 100000, ''],
];

// Phase 5: appeals, reviewer queues and the advertiser quality flag (own form).
$appealFields = [
    'appeals.window_hours' => ['Earners may appeal a rejection within (hours)', 1, 720, 'One appeal per rejected proof, not after a resubmission.'],
    'reviews.claim_minutes' => ['Reviewer claim lock (minutes)', 5, 240, 'How long a claimed appeal or stale proof stays locked to one reviewer.'],
    'appeals.flag_min_overturns' => ['Flag an advertiser after this many overturned rejections', 1, 100, ''],
    'appeals.flag_rate_percent' => ['… and an overturn rate of at least (%)', 1, 100, 'Overturned ÷ decided appeals against the advertiser\'s rejections.'],
    'appeals.flag_window_days' => ['Counting the last (days)', 1, 365, 'Flags are shown to admins only; nothing happens automatically.'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && yolotask_post_str('section') === 'appeals') {
    yolotask_admin_csrf_or_back('task-settings.php#appeals');
    try {
        $values = [];
        foreach ($appealFields as $key => [$label, $min, $max]) {
            $raw = yolotask_post_str(str_replace('.', '_', $key));
            if (!preg_match('/^\d{1,7}$/', $raw) || (int) $raw < $min || (int) $raw > $max) throw new RuntimeException("{$label} must be between {$min} and {$max}.");
            $values[$key] = (string) (int) $raw;
        }
        Settings::save($pdo, (int) $admin['id'], $values);
        yolotask_flash('Appeal and review settings saved.');
    } catch (RuntimeException $e) {
        yolotask_flash($e->getMessage(), 'error');
    } catch (Throwable $e) {
        Logger::error('admin.appeal_settings_failed', ['exception' => get_class($e)]);
        yolotask_flash('Something went wrong. Please try again.', 'error');
    }
    yolotask_redirect('task-settings.php#appeals');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    yolotask_admin_csrf_or_back('task-settings.php');
    try {
        $values = [];
        foreach ($fields as $key => [$label, $min, $max]) {
            $raw = yolotask_post_str(str_replace('.', '_', $key));
            if (!preg_match('/^\d{1,7}$/', $raw) || (int) $raw < $min || (int) $raw > $max) throw new RuntimeException("{$label} must be between {$min} and {$max}.");
            $values[$key] = (string) (int) $raw;
        }
        $mb = yolotask_post_str('tasks_proof_max_mb');
        if (!preg_match('/^\d+(\.\d)?$/', $mb) || (float) $mb < 0.5 || (float) $mb > 10) throw new RuntimeException('Proof size limit must be between 0.5 and 10 MB.');
        $values['tasks.proof_max_bytes'] = (string) (int) round((float) $mb * 1048576);
        if ((int) $values['tasks.auto_approve_min_hours'] > (int) $values['tasks.auto_approve_max_hours']) throw new RuntimeException('The shortest auto-approve window cannot be above the longest.');
        $d = (int) $values['tasks.auto_approve_default_hours'];
        if ($d < (int) $values['tasks.auto_approve_min_hours'] || $d > (int) $values['tasks.auto_approve_max_hours']) throw new RuntimeException('The default auto-approve window must sit between the shortest and longest.');
        if ((int) $values['campaigns.min_quantity'] > (int) $values['campaigns.max_quantity']) throw new RuntimeException('The minimum quantity cannot be above the maximum.');
        Settings::save($pdo, (int) $admin['id'], $values);
        yolotask_flash('Task settings saved.');
    } catch (RuntimeException $e) {
        yolotask_flash($e->getMessage(), 'error');
    } catch (Throwable $e) {
        Logger::error('admin.task_settings_failed', ['exception' => get_class($e)]);
        yolotask_flash('Something went wrong. Please try again.', 'error');
    }
    yolotask_redirect('task-settings.php');
}

$all = Settings::all($pdo);
$csrf = Csrf::token();
ob_start();
?>
<?= yolotask_admin_market_tabs($pdo, 'settings') ?>
<div class="wallet-grid">
  <section class="card" aria-labelledby="ts-title">
    <div class="card-header"><h2 id="ts-title">Task and campaign rules</h2></div>
    <form method="post" action="task-settings.php" class="stack">
      <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
      <?php foreach ($fields as $key => [$label, $min, $max, $hint]): $name = str_replace('.', '_', $key); ?>
        <div class="field"><label class="label" for="<?= h($name) ?>"><?= h($label) ?></label>
          <input class="input" id="<?= h($name) ?>" name="<?= h($name) ?>" type="number" min="<?= $min ?>" max="<?= $max ?>" required value="<?= (int) $all[$key] ?>">
          <?php if ($hint !== ''): ?><p class="input-hint"><?= h($hint) ?></p><?php endif; ?></div>
      <?php endforeach; ?>
      <div class="field"><label class="label" for="tasks_proof_max_mb">Proof screenshot size limit (MB)</label>
        <input class="input" id="tasks_proof_max_mb" name="tasks_proof_max_mb" inputmode="decimal" required value="<?= h(rtrim(rtrim(number_format((int) $all['tasks.proof_max_bytes'] / 1048576, 1, '.', ''), '0'), '.')) ?>">
        <p class="input-hint">The server's upload_max_filesize and post_max_size must be at least this.</p></div>
      <button class="btn btn-primary" type="submit">Save task settings</button>
    </form>
  </section>
  <section class="card" id="appeals" aria-labelledby="as-title">
    <div class="card-header"><h2 id="as-title">Appeals and reviews</h2></div>
    <form method="post" action="task-settings.php" class="stack">
      <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="section" value="appeals">
      <?php foreach ($appealFields as $key => [$label, $min, $max, $hint]): $name = str_replace('.', '_', $key); ?>
        <div class="field"><label class="label" for="<?= h($name) ?>"><?= h($label) ?></label>
          <input class="input" id="<?= h($name) ?>" name="<?= h($name) ?>" type="number" min="<?= $min ?>" max="<?= $max ?>" required value="<?= (int) $all[$key] ?>">
          <?php if ($hint !== ''): ?><p class="input-hint"><?= h($hint) ?></p><?php endif; ?></div>
      <?php endforeach; ?>
      <button class="btn btn-primary" type="submit">Save appeal settings</button>
    </form>
  </section>
</div>
<?php
yolotask_render_app($admin, 'Task settings', (string) ob_get_clean(), 'market');
