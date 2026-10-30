<?php
declare(strict_types=1);

use YoloTask\Campaigns\Campaigns;
use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Money\Wallet;

require_once __DIR__ . '/tasks-helpers.php';

if (yolotask_role() !== 'advertiser') yolotask_error_page(404);
yolotask_private_headers();
try {
    $pdo = Database::connection();
    $user = yolotask_require_user($pdo);
    $uid = (int) $user['id'];
    $status = is_string($_GET['status'] ?? null) ? $_GET['status'] : 'open';
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $list = Campaigns::listForAdvertiser($pdo, $uid, $status, $page);
    $stats = Campaigns::advertiserStats($pdo, $uid);
    $wallet = Wallet::forUser($pdo, $uid);
} catch (Throwable $e) {
    Logger::error('campaigns.list_failed', ['exception' => get_class($e), 'message' => $e->getMessage()]);
    yolotask_error_page(500);
}
$filters = ['open' => 'Open', 'closed' => 'Finished', 'all' => 'All'];
ob_start();
?>
<div class="wallet-grid">
  <section class="card card-glow dash-hero" aria-labelledby="camp-hero">
    <p class="text-xs fw-bold text-primary" id="camp-hero">YOUR CAMPAIGNS</p>
    <div class="wallet-sub">
      <span>Live <strong><?= (int) ($stats['live'] ?? 0) ?></strong></span>
      <span>To review <strong><?= (int) ($stats['to_review'] ?? 0) ?></strong></span>
      <span>Spent <strong><?= h(yolotask_money($stats['spent'] ?? 0)) ?></strong></span>
      <span>In escrow <strong><?= h(yolotask_money($stats['escrow'] ?? 0)) ?></strong></span>
      <span>Wallet <strong><?= h(yolotask_money($wallet['available_kobo'])) ?></strong></span>
    </div>
    <div class="hero-actions">
      <a class="btn btn-primary btn-sm" href="campaign-new.php">New campaign</a>
      <a class="btn btn-secondary btn-sm" href="campaign-settings.php">Approval settings</a>
    </div>
  </section>
  <section class="card" aria-labelledby="list-title">
    <div class="card-header"><h2 id="list-title">Campaigns</h2><span class="badge badge-info"><?= number_format($list['total']) ?></span></div>
    <nav class="money-tabs" aria-label="Filter" style="padding:0">
      <?php foreach ($filters as $k => $label): ?><a href="campaigns.php?status=<?= h($k) ?>"<?= $k === $status ? ' class="active"' : '' ?>><?= h($label) ?></a><?php endforeach; ?>
    </nav>
    <?php if ($list['rows'] === []): ?>
      <div class="empty-state"><p class="text-faint">No campaigns here yet. Create one and real people will follow, like, comment and more.</p><a class="btn btn-primary" href="campaign-new.php">Create a campaign</a></div>
    <?php else: ?>
      <ul class="tx-list">
        <?php foreach ($list['rows'] as $c): ?>
          <li class="tx-row campaign-row">
            <span class="tx-icon platform-icon" data-platform="<?= h((string) $c['platform_slug']) ?>"><?= h(mb_substr((string) $c['platform_name'], 0, 1)) ?></span>
            <div class="tx-meta"><strong><a href="campaign.php?id=<?= (int) $c['id'] ?>"><?= h((string) $c['title']) ?></a></strong>
              <span class="text-xs text-faint"><?= h((string) $c['platform_name']) ?> · <?= h((string) $c['task_type_name']) ?> · <?= number_format((int) $c['approved_count']) ?>/<?= number_format((int) $c['quantity']) ?> done<?= (int) $c['pending_count'] > 0 ? ' · ' . (int) $c['pending_count'] . ' to review' : '' ?></span>
              <?= yolotask_progress((int) $c['approved_count'], (int) $c['pending_count'], (int) $c['quantity']) ?></div>
            <div class="tx-amount"><span><?= h(yolotask_money($c['budget_kobo'])) ?></span><?= yolotask_campaign_badge((string) $c['status']) ?></div>
          </li>
        <?php endforeach; ?>
      </ul>
      <?= yolotask_pager($list, static fn (int $p): string => 'campaigns.php?' . http_build_query(['status' => $status, 'page' => $p])) ?>
    <?php endif; ?>
  </section>
</div>
<?php
yolotask_render_app($user, 'Campaigns', (string) ob_get_clean(), 'campaigns');
