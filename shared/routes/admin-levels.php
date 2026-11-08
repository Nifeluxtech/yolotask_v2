<?php
declare(strict_types=1);

use YoloTask\Core\Logger;
use YoloTask\Core\Settings;
use YoloTask\Growth\Checkin;
use YoloTask\Growth\Levels;
use YoloTask\Money\Fees;
use YoloTask\Money\Money;
use YoloTask\Security\Csrf;

require_once __DIR__ . '/growth-helpers.php';
require_once __DIR__ . '/money-helpers.php';

/* Admin: earner levels (approved tasks + approval rate -> tasks held at once,
   withdrawal fee override, daily check-in reward) and the daily check-in switch. */
[$pdo, $admin] = yolotask_admin_growth_boot('admin.levels_load_failed');
$adminId = (int) $admin['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    yolotask_admin_csrf_or_back('levels.php');
    $action = yolotask_post_str('action');
    try {
        if ($action === 'toggle') {
            Settings::save($pdo, $adminId, ['levels.enabled' => yolotask_post_str('enabled') === '1' ? '1' : '0']);
            yolotask_flash('Earner levels ' . (yolotask_post_str('enabled') === '1' ? 'switched on.' : 'switched off. Everyone uses the task settings limit.'));
        } elseif ($action === 'checkin_toggle') {
            $on = yolotask_post_str('enabled') === '1';
            Checkin::setEnabled($pdo, $adminId, $on);
            yolotask_flash('Daily check-in ' . ($on ? 'switched on. Earners are paid the amount set on their level (₦0 = no check-in for that level).' : 'switched off.'));
        } elseif ($action === 'save') {
            $id = (int) yolotask_post_str('id');
            foreach (['min_approved' => 'Approved tasks', 'min_rate' => 'Approval rate', 'max_active' => 'Tasks held at once'] as $f => $label) {
                if (!preg_match('/^\d{1,7}$/', yolotask_post_str($f))) throw new RuntimeException($label . ' must be a whole number.');
            }
            // Withdrawal fee override: empty = the base fee. Check-in reward: empty = ₦0 (off for this level).
            // Older forms without these fields keep the level's current values.
            $existing = null;
            foreach (Levels::all($pdo) as $l) if ($l['id'] === $id) $existing = $l;
            $feeBps = $existing['withdrawal_fee_bps'] ?? null;
            if (array_key_exists('withdrawal_fee', $_POST)) {
                $feeRaw = trim(yolotask_post_str('withdrawal_fee'));
                $feeBps = $feeRaw === '' ? null : Fees::parsePercent($feeRaw);
                if ($feeRaw !== '' && $feeBps === null) throw new RuntimeException('The level withdrawal fee must be a percentage (up to two decimals), or empty to use the base fee.');
            }
            $checkinKobo = (int) ($existing['checkin_reward_kobo'] ?? 0);
            if (array_key_exists('checkin_reward', $_POST)) {
                $ckRaw = trim(yolotask_post_str('checkin_reward'));
                $parsed = $ckRaw === '' ? 0 : yolotask_parse_naira($ckRaw);
                if ($parsed === null) throw new RuntimeException('Enter the daily check-in reward in naira, for example 20 (0 = no check-in for this level).');
                $checkinKobo = $parsed;
            }
            Levels::save($pdo, $adminId, $id > 0 ? $id : null, yolotask_post_str('name'), (int) yolotask_post_str('min_approved'), (int) yolotask_post_str('min_rate'),
                (int) yolotask_post_str('max_active'), yolotask_post_str('active') === '1', $feeBps, $checkinKobo);
            yolotask_flash($id > 0 ? 'Level updated.' : 'Level added.');
        } elseif ($action === 'delete') {
            Levels::delete($pdo, $adminId, (int) yolotask_post_str('id'));
            yolotask_flash('Level deleted.');
        }
    } catch (RuntimeException $e) {
        yolotask_flash($e->getMessage(), 'error');
    } catch (Throwable $e) {
        Logger::error('admin.levels_action_failed', ['action' => $action, 'exception' => get_class($e)]);
        yolotask_flash('Something went wrong. Please try again.', 'error');
    }
    yolotask_redirect('levels.php');
}

$levels = Levels::all($pdo);
$enabled = Levels::enabled($pdo);
$fallback = Settings::int($pdo, 'tasks.max_active_reservations');
$baseFee = Fees::withdrawalBaseBps($pdo);
$checkinOn = Checkin::enabled($pdo);
$ckStats = Checkin::stats($pdo);
$csrf = Csrf::token();
$row = static function (?array $l) use ($csrf, $baseFee): string {
    ob_start(); ?>
    <form method="post" action="levels.php" class="stack level-form">
      <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int) ($l['id'] ?? 0) ?>">
      <div class="grid grid-2 form-pair">
        <div class="field"><label class="label">Name</label><input class="input" name="name" maxlength="40" required value="<?= h((string) ($l['name'] ?? '')) ?>" placeholder="e.g. Platinum"></div>
        <div class="field"><label class="label">From approved tasks</label><input class="input" name="min_approved" inputmode="numeric" required value="<?= h((string) ($l['min_approved'] ?? '')) ?>"></div>
        <div class="field"><label class="label">Minimum approval rate (%)</label><input class="input" name="min_rate" inputmode="numeric" required value="<?= h((string) ($l['min_approval_rate'] ?? '0')) ?>"></div>
        <div class="field"><label class="label">Tasks held at once</label><input class="input" name="max_active" inputmode="numeric" required value="<?= h((string) ($l['max_active_reservations'] ?? '3')) ?>"></div>
        <div class="field"><label class="label">Withdrawal fee (%)</label><input class="input" name="withdrawal_fee" inputmode="decimal" pattern="[0-9]{1,2}(\.[0-9]{1,2})?" placeholder="<?= h(rtrim(rtrim(number_format($baseFee / 100, 2, '.', ''), '0'), '.')) ?> (base)" value="<?= isset($l['withdrawal_fee_bps']) && $l['withdrawal_fee_bps'] !== null ? h(rtrim(rtrim(number_format($l['withdrawal_fee_bps'] / 100, 2, '.', ''), '0'), '.')) : '' ?>">
          <p class="input-hint level-fee-hint">Empty = base fee (<?= h(Fees::percent($baseFee)) ?>). Can only be lower.<?= isset($l['withdrawal_fee_bps']) && $l['withdrawal_fee_bps'] !== null && $l['withdrawal_fee_bps'] > $baseFee ? ' Above the current base, so ' . h(Fees::percent($baseFee)) . ' applies.' : '' ?></p></div>
        <div class="field"><label class="label">Daily check-in reward (₦)</label><input class="input" name="checkin_reward" inputmode="decimal" value="<?= h(Money::decimal((int) ($l['checkin_reward_kobo'] ?? 0))) ?>">
          <p class="input-hint">0 = no check-in for this level.</p></div>
      </div>
      <div class="inline-form">
        <label class="toggle-inline"><input type="checkbox" name="active" value="1"<?= ($l['is_active'] ?? 1) ? ' checked' : '' ?>> Active</label>
        <button class="btn btn-<?= $l === null ? 'primary' : 'secondary' ?> btn-sm" type="submit"><?= $l === null ? 'Add level' : 'Save level' ?></button>
      </div>
    </form>
    <?php return (string) ob_get_clean();
};
ob_start();
?>
<?= yolotask_admin_growth_tabs($pdo, 'levels') ?>
<section class="card" aria-labelledby="lv-title">
  <div class="card-header"><h2 id="lv-title">Earner levels</h2><span class="badge <?= $enabled ? 'badge-success' : 'badge-warning' ?>"><?= $enabled ? 'On' : 'Off' ?></span></div>
  <p class="text-sm text-faint">An earner holds the highest active level whose approved-task count and approval rate (approved ÷ decided proofs) they meet. The level sets how many tasks they can hold at once, its withdrawal fee (blank = the base fee) and its daily check-in reward. With levels off, everyone can hold <?= (int) $fallback ?> (Task settings), pays the base withdrawal fee (<?= h(Fees::percent($baseFee)) ?>) and has no daily check-in.</p>
  <form method="post" action="levels.php" class="inline-form">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="toggle">
    <label class="toggle-inline"><input type="checkbox" name="enabled" value="1"<?= $enabled ? ' checked' : '' ?>> Levels on</label>
    <button class="btn btn-ghost btn-sm" type="submit">Save</button>
  </form>
</section>
<section class="card" aria-labelledby="ck-title" data-checkin-admin>
  <div class="card-header"><h2 id="ck-title">Daily check-in</h2><span class="badge <?= $checkinOn ? 'badge-success' : 'badge-warning' ?>"><?= $checkinOn ? 'On' : 'Off' ?></span></div>
  <p class="text-sm text-faint">Earners with a confirmed email can check in once per Lagos day (midnight to midnight WAT) from their dashboard and are paid the reward set on their level below (₦0 = no check-in for that level; with levels off nobody can check in). The dashboard shows their streak of consecutive days; a missed day starts it again. Today: <?= number_format($ckStats['today_count']) ?> check-ins, <?= h(Money::format($ckStats['today_kobo'])) ?>. All time: <?= h(Money::format($ckStats['total_kobo'])) ?>.</p>
  <form method="post" action="levels.php" class="inline-form">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="checkin_toggle">
    <label class="toggle-inline"><input type="checkbox" name="enabled" value="1"<?= $checkinOn ? ' checked' : '' ?>> Daily check-in on</label>
    <button class="btn btn-ghost btn-sm" type="submit">Save</button>
  </form>
</section>
<?php foreach ($levels as $l): ?>
  <section class="card" aria-label="<?= h($l['name']) ?>">
    <div class="card-header"><h3><?= h($l['name']) ?></h3><span class="user-tags"><span class="badge badge-info"><?= number_format($l['min_approved']) ?>+ tasks · <?= (int) $l['min_approval_rate'] ?>%+</span><span class="badge badge-info" data-level-fee="<?= (int) min($baseFee, $l['withdrawal_fee_bps'] ?? $baseFee) ?>">Fee <?= h(Fees::percent(min($baseFee, $l['withdrawal_fee_bps'] ?? $baseFee))) ?></span><?php if ($l['checkin_reward_kobo'] > 0): ?><span class="badge badge-success" data-level-checkin="<?= (int) $l['checkin_reward_kobo'] ?>">Check-in <?= h(Money::format($l['checkin_reward_kobo'])) ?></span><?php endif; ?><?php if (!$l['is_active']): ?><span class="badge badge-warning">Inactive</span><?php endif; ?></span></div>
    <?= $row($l) ?>
    <form method="post" action="levels.php" style="margin-top:8px"><input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $l['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit" data-confirm="Delete the <?= h($l['name']) ?> level?">Delete</button></form>
  </section>
<?php endforeach; ?>
<section class="card" aria-labelledby="lv-new"><div class="card-header"><h3 id="lv-new">Add a level</h3></div><?= $row(null) ?></section>
<?php
yolotask_render_app($admin, 'Growth', (string) ob_get_clean(), 'market');
