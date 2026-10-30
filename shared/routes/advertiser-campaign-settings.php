<?php
declare(strict_types=1);

use YoloTask\Campaigns\Campaigns;
use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Security\Csrf;

require_once __DIR__ . '/tasks-helpers.php';

if (yolotask_role() !== 'advertiser') yolotask_error_page(404);
yolotask_private_headers();
try {
    $pdo = Database::connection();
    $user = yolotask_require_user($pdo);
} catch (Throwable $e) {
    Logger::error('campaign_settings.load_failed', ['exception' => get_class($e)]);
    yolotask_error_page(500);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    yolotask_csrf_or_back('campaign-settings.php');
    try {
        Campaigns::saveAdvertiserSettings($pdo, $user, (int) ($_POST['auto_approve_hours'] ?? 0));
        yolotask_flash('Approval settings saved.');
    } catch (RuntimeException $e) {
        yolotask_flash($e->getMessage(), 'error');
    } catch (Throwable $e) {
        Logger::error('campaign_settings.save_failed', ['exception' => get_class($e)]);
        yolotask_flash('Something went wrong. Please try again.', 'error');
    }
    yolotask_redirect('campaign-settings.php');
}
$hours = Campaigns::advertiserAutoApproveHours($pdo, (int) $user['id']);
$b = Campaigns::autoApproveBounds($pdo);
$csrf = Csrf::token();
ob_start();
?>
<div class="wallet-grid">
  <section class="card" aria-labelledby="as-title">
    <div class="card-header"><h2 id="as-title">Automatic approval</h2><span class="badge badge-info"><?= $hours ?> h</span></div>
    <p class="text-sm text-faint">Earners are paid when you approve their proof. If you do not review a proof in time, it is approved automatically so honest earners are never left waiting.</p>
    <form method="post" action="campaign-settings.php" class="stack">
      <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
      <div class="field"><label class="label" for="auto_approve_hours">Approve automatically after (hours)</label>
        <input class="input" id="auto_approve_hours" name="auto_approve_hours" type="number" min="<?= $b['min'] ?>" max="<?= $b['max'] ?>" required value="<?= $hours ?>">
        <p class="input-hint">Between <?= $b['min'] ?> and <?= $b['max'] ?> hours (<?= intdiv($b['max'], 24) ?> days). Applies to new proofs on all your open campaigns.</p></div>
      <button class="btn btn-primary" type="submit">Save</button>
    </form>
  </section>
  <p class="text-center text-sm"><a href="campaigns.php">Back to campaigns</a></p>
</div>
<?php
yolotask_render_app($user, 'Approval settings', (string) ob_get_clean(), 'campaigns');
