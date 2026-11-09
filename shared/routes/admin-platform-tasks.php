<?php
declare(strict_types=1);

use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\PlatformTasks\PlatformTasks;
use YoloTask\Security\Csrf;

require_once __DIR__ . '/tasks-helpers.php';

/*
 * Admin: YOLOTASK tasks (migration 0019). Tasks the platform provides itself,
 * grouped in bundles (one social account, several tasks under it, each with
 * its own link, task type, instructions, reward and proof). Reward percent,
 * bulk import (CSV with header bundle_key,platform,account_name,task_type,
 * url,instructions), bundle create / edit / enable / disable, add tasks by
 * pasting several links at once, enable / disable / remove / edit single
 * tasks, completion counts. Earners see these tasks in the normal feed after
 * advertisers' tasks, with no label.
 */
if (yolotask_role() !== 'admin') yolotask_error_page(404);
yolotask_private_headers();
try {
    $pdo = Database::connection();
    $admin = yolotask_require_user($pdo);
} catch (Throwable $e) {
    Logger::error('admin.platform_tasks_load_failed', ['exception' => get_class($e)]);
    yolotask_error_page(500);
}
$adminId = (int) $admin['id'];
$plural = static fn (int $n, string $one, string $many = ''): string => number_format($n) . ' ' . ($n === 1 ? $one : ($many !== '' ? $many : $one . 's'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $back = 'platform-tasks.php' . (is_string($_POST['back'] ?? null) && preg_match('/^[a-z0-9_=&%#.+\-]*$/i', $_POST['back']) ? ($_POST['back'] !== '' ? '?' . ltrim($_POST['back'], '?') : '') : '');
    if ($_POST === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) { yolotask_flash('That file is too large.', 'error'); yolotask_redirect('platform-tasks.php'); }
    yolotask_admin_csrf_or_back($back);
    $action = yolotask_post_str('action');
    try {
        switch ($action) {
            case 'settings':
                $pct = yolotask_post_str('reward_percent');
                if (!preg_match('/^\d{1,3}$/', $pct)) throw new RuntimeException('The reward must be 1 to 100% of the normal reward.');
                $n = PlatformTasks::saveRewardPercent($pdo, $adminId, (int) $pct);
                yolotask_flash('Reward saved.' . ($n > 0 ? ' ' . $plural($n, 'task reward') . ' updated; slots already started keep their reward.' : ''));
                break;
            case 'import':
                $text = yolotask_post_str('csv_text');
                $file = $_FILES['csv'] ?? null;
                if (is_array($file) && isset($file['error']) && !is_array($file['error']) && (int) $file['error'] !== UPLOAD_ERR_NO_FILE) {
                    if ((int) $file['error'] !== UPLOAD_ERR_OK || (int) $file['size'] > 2097152 || !is_uploaded_file((string) $file['tmp_name'])) throw new RuntimeException('Upload a CSV file of up to 2 MB.');
                    $text = (string) file_get_contents((string) $file['tmp_name']);
                }
                if (trim($text) === '') throw new RuntimeException('Paste the CSV rows or choose a CSV file.');
                $dry = !empty($_POST['dry_run']);
                $res = PlatformTasks::import($pdo, $adminId, PlatformTasks::parseCsv($text), $dry);
                $msg = ($dry ? 'Dry run (nothing saved): ' : '') . $plural($res['added'], 'task') . ($dry ? ' would be' : '') . ' added in ' . $plural($res['bundles_created'], 'new bundle');
                if ($res['bundles_reused'] > 0) $msg .= ' (' . $plural($res['bundles_reused'], 'existing bundle') . ' reused)';
                if ($res['skipped'] > 0) $msg .= ', ' . $plural($res['skipped'], 'duplicate') . ' skipped';
                if ($res['restored'] > 0) $msg .= ', ' . $plural($res['restored'], 'removed task') . ' restored';
                if ($res['errors'] !== []) $msg .= ', ' . $plural(count($res['errors']), 'row') . ' not added: ' . implode(' ', array_slice($res['errors'], 0, 5)) . (count($res['errors']) > 5 ? ' …' : '');
                yolotask_flash($msg . '.', $res['errors'] !== [] ? ($res['added'] > 0 ? 'warning' : 'error') : 'success');
                break;
            case 'create_bundle':
                $id = PlatformTasks::createBundle($pdo, $adminId, (int) ($_POST['platform_id'] ?? 0), yolotask_post_str('account_name'), yolotask_post_str('profile_url'), yolotask_post_str('bundle_key'));
                yolotask_flash('Bundle created. Add its tasks below.');
                yolotask_redirect('platform-tasks.php?bundle=' . $id . '#bundle');
                // no break: redirect exits
            case 'update_bundle':
                PlatformTasks::updateBundle($pdo, $adminId, (int) ($_POST['bundle_id'] ?? 0), yolotask_post_str('account_name'), yolotask_post_str('profile_url'));
                yolotask_flash('Bundle saved. Task titles keep their wording; edit a task to rename it.');
                break;
            case 'enable_bundle':
            case 'disable_bundle':
                $ids = is_array($_POST['bundle_ids'] ?? null) ? $_POST['bundle_ids'] : [(int) ($_POST['bundle_id'] ?? 0)];
                $n = PlatformTasks::setBundlesEnabled($pdo, $adminId, $ids, $action === 'enable_bundle');
                yolotask_flash($plural($n, 'bundle') . ($action === 'enable_bundle' ? ' enabled.' : ' disabled. Earners already working on one of its tasks can still submit it.'));
                break;
            case 'add_tasks':
                $res = PlatformTasks::addTasks($pdo, $adminId, (int) ($_POST['bundle_id'] ?? 0), (int) ($_POST['task_type_id'] ?? 0), yolotask_post_str('links'), yolotask_post_str('instructions'));
                $msg = $plural($res['added'], 'task') . ' added';
                if ($res['skipped'] > 0) $msg .= ', ' . $plural($res['skipped'], 'duplicate') . ' skipped';
                if ($res['restored'] > 0) $msg .= ', ' . $plural($res['restored'], 'removed task') . ' restored';
                if ($res['errors'] !== []) $msg .= ', ' . $plural(count($res['errors']), 'link') . ' not added: ' . implode(' ', array_slice($res['errors'], 0, 5));
                yolotask_flash($msg . '.', $res['errors'] !== [] ? ($res['added'] > 0 ? 'warning' : 'error') : 'success');
                break;
            case 'enable':
            case 'disable':
                $ids = is_array($_POST['ids'] ?? null) ? $_POST['ids'] : [(int) ($_POST['id'] ?? 0)];
                $n = PlatformTasks::setTasksEnabled($pdo, $adminId, $ids, $action === 'enable');
                yolotask_flash($plural($n, 'task') . ($action === 'enable' ? ' enabled.' : ' disabled. Earners already working on one can still submit it.'));
                break;
            case 'remove':
                PlatformTasks::removeTask($pdo, $adminId, (int) ($_POST['id'] ?? 0));
                yolotask_flash('Task removed. Adding the same link and task type again brings it back.');
                break;
            case 'edit':
                PlatformTasks::edit($pdo, $adminId, (int) ($_POST['id'] ?? 0), yolotask_post_str('title'), yolotask_post_str('instructions'), yolotask_post_str('proof'));
                yolotask_flash('Task saved.');
                break;
        }
    } catch (RuntimeException $e) {
        yolotask_flash($e->getMessage(), 'error');
    } catch (Throwable $e) {
        Logger::error('admin.platform_tasks_action_failed', ['action' => $action, 'exception' => get_class($e), 'message' => $e->getMessage()]);
        yolotask_flash('Something went wrong. Please try again.', 'error');
    }
    yolotask_redirect($back);
}

$str = static fn (string $k, int $max = 100): string => trim(is_string($_GET[$k] ?? null) ? mb_substr($_GET[$k], 0, $max) : '');
$fStatus = $str('status');
$fPlatform = max(0, (int) ($_GET['platform'] ?? 0));
$q = $str('q');
$page = max(1, (int) ($_GET['page'] ?? 1));
$bundleId = max(0, (int) ($_GET['bundle'] ?? 0));
try {
    $stats = PlatformTasks::stats($pdo);
    $bundles = PlatformTasks::listBundles($pdo, $fStatus, $fPlatform, $q, $page);
    $categories = PlatformTasks::categories($pdo);
    $platforms = PlatformTasks::platforms($pdo);
    $types = PlatformTasks::taskTypes($pdo);
    $percent = PlatformTasks::rewardPercent($pdo);
    $bundle = $bundleId > 0 ? PlatformTasks::bundle($pdo, $bundleId) : null;
    if ($bundleId > 0 && $bundle === null) yolotask_error_page(404);
    $bundleTasks = $bundle !== null ? PlatformTasks::bundleTasks($pdo, $bundleId) : [];
} catch (Throwable $e) {
    Logger::error('admin.platform_tasks_page_failed', ['exception' => get_class($e), 'message' => $e->getMessage()]);
    yolotask_error_page(500);
}
$csrf = Csrf::token();
$keep = static fn (array $over = []): string => http_build_query(array_filter(array_merge(['status' => $fStatus, 'platform' => $fPlatform ?: null, 'q' => $q, 'page' => $page > 1 ? $page : null, 'bundle' => $bundleId ?: null], $over), static fn ($v): bool => $v !== null && $v !== '' && $v !== 0));
$back = $keep();
$opt = static fn (string $value, string $label, string $current): string => '<option value="' . h($value) . '"' . ($value === $current ? ' selected' : '') . '>' . h($label) . '</option>';
$hidden = '<input type="hidden" name="_csrf" value="' . h($csrf) . '"><input type="hidden" name="back" value="' . h($back) . '">';
$statusBadge = static fn (string $s): string => '<span class="badge ' . ($s === 'live' ? 'badge-success' : ($s === 'paused' ? 'badge-warning' : 'badge-info')) . '">' . ($s === 'live' ? 'Enabled' : ($s === 'paused' ? 'Disabled' : 'Removed')) . '</span>';
ob_start();
?>
<?= yolotask_admin_market_tabs($pdo, 'platform') ?>
<div class="wallet-grid">
  <section class="grid grid-4 dash-stats money-stats" aria-label="YOLOTASK tasks overview">
    <article class="stat-card stat-success"><span class="stat-label">Live tasks</span><span class="stat-value" data-stat="live"><?= number_format($stats['live']) ?></span><span class="text-xs text-faint"><?= number_format($stats['paused']) ?> disabled</span></article>
    <article class="stat-card"><span class="stat-label">Bundles</span><span class="stat-value" data-stat="bundles"><?= number_format($stats['bundles']) ?></span><span class="text-xs text-faint">Social accounts</span></article>
    <article class="stat-card stat-warning"><span class="stat-label">Completed</span><span class="stat-value" data-stat="approved"><?= number_format($stats['approved']) ?></span><span class="text-xs text-faint"><?= number_format($stats['pending']) ?> proofs waiting for reviewers</span></article>
    <article class="stat-card"><span class="stat-label">Rewards paid</span><span class="stat-value" data-stat="payouts"><?= h(yolotask_money($stats['payouts'])) ?></span><span class="text-xs text-faint">Paid by YOLOTASK</span></article>
  </section>

  <?php if ($bundle !== null): ?>
  <section class="card card-glow" id="bundle" aria-labelledby="b-title" data-bundle-detail="<?= (int) $bundle['id'] ?>">
    <div class="card-header"><h2 id="b-title"><?= h((string) $bundle['account_name']) ?> · <?= h((string) $bundle['platform_name']) ?></h2><?= $statusBadge((string) $bundle['status']) ?></div>
    <p class="text-sm text-faint">Key <code><?= h((string) $bundle['bundle_key']) ?></code><?php if ($bundle['profile_url']): ?> · <?= yolotask_task_link((string) $bundle['profile_url'], 'Open profile') ?><?php endif; ?> · <a href="platform-tasks.php?<?= h($keep(['bundle' => null])) ?>">All bundles</a></p>
    <div class="review-actions" style="margin-bottom:.75rem">
      <form method="post" action="platform-tasks.php"><?= $hidden ?><input type="hidden" name="bundle_id" value="<?= (int) $bundle['id'] ?>">
        <?php if ((string) $bundle['status'] === 'live'): ?><button class="btn btn-danger-outline btn-sm" type="submit" name="action" value="disable_bundle">Disable bundle</button><?php else: ?><button class="btn btn-secondary btn-sm" type="submit" name="action" value="enable_bundle">Enable bundle</button><?php endif; ?>
      </form>
    </div>
    <details><summary class="text-sm">Edit account name or profile link</summary>
      <form method="post" action="platform-tasks.php" class="stack" style="margin-top:6px">
        <?= $hidden ?><input type="hidden" name="action" value="update_bundle"><input type="hidden" name="bundle_id" value="<?= (int) $bundle['id'] ?>">
        <div class="grid grid-2 form-pair">
          <div class="field"><label class="label" for="eb_name">Account name</label><input class="input" id="eb_name" name="account_name" maxlength="80" required value="<?= h((string) $bundle['account_name']) ?>"></div>
          <div class="field"><label class="label" for="eb_url">Profile link (optional)</label><input class="input" id="eb_url" name="profile_url" maxlength="500" value="<?= h((string) ($bundle['profile_url'] ?? '')) ?>"></div>
        </div>
        <button class="btn btn-primary btn-sm" type="submit">Save bundle</button>
      </form>
    </details>

    <h3 style="margin-top:1rem">Add tasks</h3>
    <form method="post" action="platform-tasks.php" class="stack" data-add-tasks>
      <?= $hidden ?><input type="hidden" name="action" value="add_tasks"><input type="hidden" name="bundle_id" value="<?= (int) $bundle['id'] ?>">
      <div class="field"><label class="label" for="at_type">Task type</label>
        <select class="input" id="at_type" name="task_type_id" required><?php foreach ($types as $t): ?><option value="<?= (int) $t['id'] ?>"><?= h((string) $t['name']) ?> · <?= h(yolotask_money(PlatformTasks::rewardFor((int) $t['base_reward_kobo'], $percent))) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label class="label" for="at_links">Links (one task per link; paste several post links at once)</label>
        <textarea class="input mono-input" id="at_links" name="links" rows="4" required placeholder="https://facebook.com/nifelux/posts/1&#10;https://facebook.com/nifelux/posts/2"></textarea></div>
      <div class="field"><label class="label" for="at_instr">Instructions (optional)</label>
        <textarea class="input" id="at_instr" name="instructions" rows="2" maxlength="2000" placeholder="Leave empty for the standard instructions for this task type"></textarea></div>
      <button class="btn btn-primary btn-sm" type="submit">Add tasks</button>
    </form>

    <h3 style="margin-top:1rem">Tasks <span class="badge badge-info"><?= count($bundleTasks) ?></span></h3>
    <?php if ($bundleTasks === []): ?>
      <div class="empty-state"><p class="text-faint">No tasks in this bundle yet.</p></div>
    <?php else: ?>
      <form method="post" action="platform-tasks.php" id="bulk-form"><?= $hidden ?></form>
      <div class="review-actions" style="margin-bottom:.5rem">
        <button class="btn btn-secondary btn-sm" type="submit" form="bulk-form" name="action" value="enable">Enable selected</button>
        <button class="btn btn-danger-outline btn-sm" type="submit" form="bulk-form" name="action" value="disable">Disable selected</button>
      </div>
      <ul class="tx-list platform-task-list">
        <?php foreach ($bundleTasks as $l): ?>
          <li class="tx-row" data-platform-task="<?= (int) $l['id'] ?>">
            <?php if ((string) $l['status'] !== 'ended'): ?><input type="checkbox" name="ids[]" value="<?= (int) $l['id'] ?>" form="bulk-form" aria-label="Select task #<?= (int) $l['id'] ?>"><?php endif; ?>
            <div class="tx-meta">
              <strong>#<?= (int) $l['id'] ?> · <?= h((string) $l['title']) ?></strong>
              <span class="text-xs text-faint"><?= h((string) $l['task_type_name']) ?> · <?= yolotask_task_link((string) $l['link_url'], 'Open link') ?></span>
              <span class="text-xs text-faint" data-completions>Completed <?= number_format((int) $l['approved_count']) ?> · waiting <?= number_format((int) $l['pending_count']) ?> · rejected <?= number_format((int) $l['rejected_count']) ?></span>
              <?php if ((string) $l['status'] !== 'ended'): ?>
              <details><summary class="text-xs">Edit</summary>
                <form method="post" action="platform-tasks.php" class="stack" style="margin-top:6px">
                  <?= $hidden ?><input type="hidden" name="action" value="edit"><input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
                  <input class="input" name="title" maxlength="120" required value="<?= h((string) $l['title']) ?>" aria-label="Title">
                  <textarea class="input" name="instructions" rows="3" maxlength="2000" required aria-label="Instructions"><?= h((string) $l['instructions']) ?></textarea>
                  <input class="input" name="proof" maxlength="500" required value="<?= h((string) $l['proof_requirements']) ?>" aria-label="Proof needed">
                  <button class="btn btn-primary btn-sm" type="submit">Save</button>
                </form>
              </details>
              <?php endif; ?>
            </div>
            <div class="tx-amount"><span class="tx-in">+<?= h(yolotask_money($l['unit_reward_kobo'])) ?></span><?= $statusBadge((string) $l['status']) ?>
              <?php if ((string) $l['status'] !== 'ended'): ?>
              <form method="post" action="platform-tasks.php"><?= $hidden ?><input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
                <?php if ((string) $l['status'] === 'live'): ?><button class="btn btn-ghost btn-sm" type="submit" name="action" value="disable">Disable</button><?php else: ?><button class="btn btn-ghost btn-sm" type="submit" name="action" value="enable">Enable</button><?php endif; ?>
                <button class="btn btn-ghost btn-sm" type="submit" name="action" value="remove" data-confirm="Remove this task? Its history stays; an earner holding it can no longer submit.">Remove</button>
              </form>
              <?php endif; ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <section class="card" id="bundles" aria-labelledby="bl-title">
    <div class="card-header"><h2 id="bl-title">Bundles</h2><span class="badge badge-info"><?= number_format($bundles['total']) ?></span></div>
    <p class="text-sm text-faint">A bundle is one social account with several tasks under it. Earners see these tasks in the normal task feed after advertisers' tasks, grouped under the account; each task is done once per earner and pays <?= (int) $percent ?>% of that task type's normal reward, paid by YOLOTASK.</p>
    <form method="get" action="platform-tasks.php#bundles" class="filter-bar">
      <input class="input" type="search" name="q" value="<?= h($q) ?>" placeholder="Account, key or link" aria-label="Search bundles">
      <select class="input" name="status" aria-label="Status"><?= $opt('', 'Enabled and disabled', $fStatus) . $opt('live', 'Enabled', $fStatus) . $opt('paused', 'Disabled', $fStatus) ?></select>
      <select class="input" name="platform" aria-label="Platform"><option value="0">Any platform</option><?php foreach ($platforms as $p): ?><option value="<?= (int) $p['id'] ?>"<?= $fPlatform === (int) $p['id'] ? ' selected' : '' ?>><?= h((string) $p['name']) ?></option><?php endforeach; ?></select>
      <button class="btn btn-primary" type="submit">Filter</button>
    </form>
    <?php if ($bundles['rows'] === []): ?>
      <div class="empty-state"><p class="text-faint">No bundles yet. Create one or import a CSV below.</p></div>
    <?php else: ?>
      <form method="post" action="platform-tasks.php" id="bundle-bulk-form"><?= $hidden ?></form>
      <div class="review-actions" style="margin-bottom:.5rem">
        <button class="btn btn-secondary btn-sm" type="submit" form="bundle-bulk-form" name="action" value="enable_bundle">Enable selected</button>
        <button class="btn btn-danger-outline btn-sm" type="submit" form="bundle-bulk-form" name="action" value="disable_bundle">Disable selected</button>
      </div>
      <ul class="tx-list">
        <?php foreach ($bundles['rows'] as $b): ?>
          <li class="tx-row" data-bundle-row="<?= (int) $b['id'] ?>">
            <input type="checkbox" name="bundle_ids[]" value="<?= (int) $b['id'] ?>" form="bundle-bulk-form" aria-label="Select bundle <?= h((string) $b['account_name']) ?>">
            <span class="tx-icon platform-icon" data-platform="<?= h((string) $b['platform_slug']) ?>"><?= h(mb_substr((string) $b['platform_name'], 0, 1)) ?></span>
            <div class="tx-meta"><strong><a href="platform-tasks.php?<?= h($keep(['bundle' => (int) $b['id']])) ?>#bundle"><?= h((string) $b['account_name']) ?> · <?= h((string) $b['platform_name']) ?></a></strong>
              <span class="text-xs text-faint"><code><?= h((string) $b['bundle_key']) ?></code> · <?= $plural((int) $b['live_tasks'], 'live task') ?><?= (int) $b['paused_tasks'] > 0 ? ', ' . number_format((int) $b['paused_tasks']) . ' disabled' : '' ?></span>
              <span class="text-xs text-faint" data-bundle-completions>Completed <?= number_format((int) $b['approved']) ?> · waiting <?= number_format((int) $b['pending']) ?></span></div>
            <div class="tx-amount"><?= $statusBadge((string) $b['status']) ?><a class="btn btn-secondary btn-sm" href="platform-tasks.php?<?= h($keep(['bundle' => (int) $b['id']])) ?>#bundle">Manage</a></div>
          </li>
        <?php endforeach; ?>
      </ul>
      <?= yolotask_pager($bundles, static fn (int $p): string => 'platform-tasks.php?' . $keep(['page' => $p, 'bundle' => null]) . '#bundles') ?>
    <?php endif; ?>
  </section>

  <section class="card" id="new-bundle" aria-labelledby="nb-title">
    <div class="card-header"><h2 id="nb-title">New bundle</h2></div>
    <form method="post" action="platform-tasks.php" class="stack">
      <?= $hidden ?><input type="hidden" name="action" value="create_bundle">
      <div class="grid grid-2 form-pair">
        <div class="field"><label class="label" for="nb_platform">Platform</label>
          <select class="input" id="nb_platform" name="platform_id" required><?php foreach ($platforms as $p): ?><option value="<?= (int) $p['id'] ?>"><?= h((string) $p['name']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label class="label" for="nb_name">Account name</label><input class="input" id="nb_name" name="account_name" maxlength="80" required placeholder="Nifelux"></div>
        <div class="field"><label class="label" for="nb_url">Profile link (optional)</label><input class="input" id="nb_url" name="profile_url" maxlength="500" placeholder="https://facebook.com/nifelux"></div>
        <div class="field"><label class="label" for="nb_key">Bundle key (optional)</label><input class="input" id="nb_key" name="bundle_key" maxlength="64" placeholder="made from the platform and name"><p class="input-hint">Ties CSV rows to this bundle.</p></div>
      </div>
      <button class="btn btn-primary" type="submit">Create bundle</button>
    </form>
  </section>

  <section class="card platform-import" id="import" aria-labelledby="pi-title">
    <div class="card-header"><h2 id="pi-title">Bulk import</h2><span class="badge badge-info">Up to <?= number_format(PlatformTasks::MAX_IMPORT_ROWS) ?> rows</span></div>
    <p class="text-sm text-faint">CSV with the header <code><?= h(implode(',', PlatformTasks::CSV_HEADER)) ?></code>. Rows with the same <code>bundle_key</code> become one bundle; each row is one task. <code>instructions</code> may be empty (standard instructions). Re-importing the same file adds nothing: a task is matched on bundle key, task type and link.</p>
    <form method="post" action="platform-tasks.php" enctype="multipart/form-data" class="stack">
      <?= $hidden ?><input type="hidden" name="action" value="import">
      <div class="field"><label class="label" for="csv_text">Paste CSV</label>
        <textarea class="input mono-input" id="csv_text" name="csv_text" rows="6" placeholder="bundle_key,platform,account_name,task_type,url,instructions&#10;nifelux-fb,facebook,Nifelux,follow,https://facebook.com/nifelux,&#10;nifelux-fb,facebook,Nifelux,like,https://facebook.com/nifelux/posts/1,"></textarea></div>
      <div class="field"><label class="label" for="csv">…or upload a CSV file</label>
        <input class="input" id="csv" name="csv" type="file" accept=".csv,text/csv,text/plain">
        <p class="input-hint">UTF-8, up to 2 MB. When a file is chosen the text box is ignored.</p></div>
      <label class="toggle-inline"><input type="checkbox" name="dry_run" value="1"> Dry run (check the file, save nothing)</label>
      <button class="btn btn-primary" type="submit">Import</button>
    </form>
  </section>

  <section class="card" id="settings" aria-labelledby="ps-title">
    <div class="card-header"><h2 id="ps-title">Reward</h2></div>
    <form method="post" action="platform-tasks.php" class="stack">
      <?= $hidden ?><input type="hidden" name="action" value="settings">
      <div class="field"><label class="label" for="reward_percent">YOLOTASK task reward (% of the task type's normal reward)</label>
        <input class="input" id="reward_percent" name="reward_percent" type="number" min="1" max="100" required value="<?= (int) $percent ?>">
        <p class="input-hint">50 = half. Rounded down to the kobo. Applies to every YOLOTASK task now; slots already started keep their reward. Task type rewards are set on Platforms &amp; prices.</p></div>
      <button class="btn btn-primary" type="submit">Save reward</button>
    </form>
  </section>

  <?php if ($categories !== []): ?>
  <section class="card" id="categories" aria-labelledby="pc-title">
    <div class="card-header"><h2 id="pc-title">Live by category</h2></div>
    <div class="table-wrap"><table class="table"><thead><tr><th>Platform</th><th>Task</th><th>Reward</th><th>Live</th><th>Completed</th></tr></thead><tbody>
      <?php foreach ($categories as $cat): ?><tr><td><?= h((string) $cat['platform_name']) ?></td><td><?= h((string) $cat['task_type_name']) ?></td><td><?= h(yolotask_money($cat['unit_reward_kobo'])) ?></td><td><?= number_format((int) $cat['n']) ?></td><td><?= number_format((int) $cat['approved']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
  </section>
  <?php endif; ?>
</div>
<?php
yolotask_render_app($admin, 'YOLOTASK tasks', (string) ob_get_clean(), 'market');
