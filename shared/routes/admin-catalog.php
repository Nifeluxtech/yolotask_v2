<?php
declare(strict_types=1);

use YoloTask\Campaigns\Pricing;
use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Money\Money;
use YoloTask\Security\Csrf;

require_once __DIR__ . '/tasks-helpers.php';

/* Admin: platforms and task types with their prices (base and premium). */
if (yolotask_role() !== 'admin') yolotask_error_page(404);
yolotask_private_headers();
try {
    $pdo = Database::connection();
    $admin = yolotask_require_user($pdo);
} catch (Throwable $e) {
    Logger::error('admin.catalog_load_failed', ['exception' => get_class($e)]);
    yolotask_error_page(500);
}
$adminId = (int) $admin['id'];
$naira = static function (string $key): int {
    $k = yolotask_parse_naira(yolotask_post_str($key));
    if ($k === null) throw new RuntimeException('Enter amounts in naira, for example 25 or 37.50.');
    return $k;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    yolotask_admin_csrf_or_back('catalog.php');
    $action = yolotask_post_str('action');
    try {
        if ($action === 'save_type') {
            Pricing::saveTaskType($pdo, $adminId, (int) ($_POST['id'] ?? 0), [
                'name' => yolotask_post_str('name'), 'proof_hint' => yolotask_post_str('proof_hint'),
                'base_price_kobo' => $naira('base_price'), 'base_reward_kobo' => $naira('base_reward'),
                'premium_price_kobo' => $naira('premium_price'), 'premium_reward_kobo' => $naira('premium_reward'),
                'is_active' => !empty($_POST['is_active']),
            ]);
            yolotask_flash('Task type saved. New campaigns use the new prices; existing campaigns keep theirs.');
        } elseif ($action === 'add_type') {
            Pricing::addTaskType($pdo, $adminId, yolotask_post_str('name'), $naira('base_price'), $naira('base_reward'));
            yolotask_flash('Task type added. Premium prices default to 1.5× the base.');
        } elseif ($action === 'save_platform') {
            Pricing::savePlatform($pdo, $adminId, (int) ($_POST['id'] ?? 0), yolotask_post_str('name'), yolotask_post_str('domains'), !empty($_POST['is_active']));
            yolotask_flash('Platform saved.');
        } elseif ($action === 'add_platform') {
            Pricing::addPlatform($pdo, $adminId, yolotask_post_str('name'), yolotask_post_str('domains'));
            yolotask_flash('Platform added.');
        }
    } catch (RuntimeException $e) {
        yolotask_flash($e->getMessage(), 'error');
    } catch (Throwable $e) {
        Logger::error('admin.catalog_save_failed', ['exception' => get_class($e), 'message' => $e->getMessage()]);
        yolotask_flash('Something went wrong. Please try again.', 'error');
    }
    yolotask_redirect('catalog.php');
}

$types = Pricing::taskTypes($pdo, false);
$platforms = Pricing::platforms($pdo, false);
$csrf = Csrf::token();
$dec = static fn ($k): string => Money::decimal((int) $k);
ob_start();
?>
<?= yolotask_admin_market_tabs($pdo, 'catalog') ?>
<div class="wallet-grid">
  <section class="card" aria-labelledby="tt-title">
    <div class="card-header"><h2 id="tt-title">Task types and prices</h2><span class="badge badge-info"><?= count($types) ?></span></div>
    <p class="text-xs text-faint">Price = what the advertiser pays per approved task. Reward = what the earner gets. The difference is YOLOTASK revenue. Premium applies to targeted campaigns.</p>
    <ul class="user-list">
      <?php foreach ($types as $t): ?>
        <li class="user-row" data-task-type="<?= h((string) $t['slug']) ?>">
          <form method="post" action="catalog.php" class="stack catalog-form">
            <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="save_type"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
            <div class="flex-between flex-wrap"><input class="input" name="name" value="<?= h((string) $t['name']) ?>" aria-label="Name" maxlength="60" required style="max-width:240px">
              <label class="text-sm"><input type="checkbox" name="is_active" value="1"<?= (int) $t['is_active'] ? ' checked' : '' ?>> Active</label></div>
            <div class="price-grid">
              <label class="field"><span class="label">Base price ₦</span><input class="input" name="base_price" inputmode="decimal" value="<?= h($dec($t['base_price_kobo'])) ?>" required></label>
              <label class="field"><span class="label">Base reward ₦</span><input class="input" name="base_reward" inputmode="decimal" value="<?= h($dec($t['base_reward_kobo'])) ?>" required></label>
              <label class="field"><span class="label">Premium price ₦</span><input class="input" name="premium_price" inputmode="decimal" value="<?= h($dec($t['premium_price_kobo'])) ?>" required></label>
              <label class="field"><span class="label">Premium reward ₦</span><input class="input" name="premium_reward" inputmode="decimal" value="<?= h($dec($t['premium_reward_kobo'])) ?>" required></label>
            </div>
            <label class="field"><span class="label">Proof hint</span><input class="input" name="proof_hint" maxlength="255" value="<?= h((string) $t['proof_hint']) ?>"></label>
            <div class="flex-between flex-wrap"><span class="text-xs text-faint">Margin <?= h(yolotask_money((int) $t['base_price_kobo'] - (int) $t['base_reward_kobo'])) ?> base · <?= h(yolotask_money((int) $t['premium_price_kobo'] - (int) $t['premium_reward_kobo'])) ?> premium</span>
              <button class="btn btn-secondary btn-sm" type="submit">Save</button></div>
          </form>
        </li>
      <?php endforeach; ?>
    </ul>
    <form method="post" action="catalog.php" class="inline-form">
      <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="add_type">
      <input class="input" name="name" required maxlength="60" placeholder="New task type" aria-label="New task type name">
      <input class="input" name="base_price" inputmode="decimal" required placeholder="Price ₦" aria-label="Base price">
      <input class="input" name="base_reward" inputmode="decimal" required placeholder="Reward ₦" aria-label="Base reward">
      <button class="btn btn-primary btn-sm" type="submit">Add task type</button>
    </form>
  </section>

  <section class="card" aria-labelledby="pl-title">
    <div class="card-header"><h2 id="pl-title">Platforms</h2><span class="badge badge-info"><?= count($platforms) ?></span></div>
    <p class="text-xs text-faint">Campaign links must be on one of the platform's domains.</p>
    <ul class="user-list">
      <?php foreach ($platforms as $p): ?>
        <li class="user-row" data-platform="<?= h((string) $p['slug']) ?>">
          <form method="post" action="catalog.php" class="inline-form" style="width:100%">
            <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="save_platform"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
            <input class="input" name="name" value="<?= h((string) $p['name']) ?>" maxlength="60" required aria-label="Platform name">
            <input class="input" name="domains" value="<?= h((string) $p['domains']) ?>" maxlength="255" required aria-label="Link domains">
            <label class="text-sm"><input type="checkbox" name="is_active" value="1"<?= (int) $p['is_active'] ? ' checked' : '' ?>> Active</label>
            <button class="btn btn-secondary btn-sm" type="submit">Save</button>
          </form>
        </li>
      <?php endforeach; ?>
    </ul>
    <form method="post" action="catalog.php" class="inline-form">
      <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="add_platform">
      <input class="input" name="name" required maxlength="60" placeholder="New platform" aria-label="New platform name">
      <input class="input" name="domains" required maxlength="255" placeholder="example.com, ex.am" aria-label="New platform domains">
      <button class="btn btn-primary btn-sm" type="submit">Add platform</button>
    </form>
  </section>
</div>
<?php
yolotask_render_app($admin, 'Platforms & prices', (string) ob_get_clean(), 'market');
