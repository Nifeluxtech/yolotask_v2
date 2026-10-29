<?php
declare(strict_types=1);

use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Money\Wallet;
use YoloTask\Payments\AfrstarClient;
use YoloTask\Payments\BankAccounts;
use YoloTask\Payments\PaymentOrders;
use YoloTask\Payments\Withdrawals;

require_once __DIR__ . '/money-helpers.php';

$role = yolotask_role();
if (!in_array($role, ['earner', 'advertiser'], true)) yolotask_error_page(404);
yolotask_private_headers();
try {
    $pdo = Database::connection();
    $user = yolotask_require_user($pdo);
    $userId = (int) $user['id'];
    $wallet = Wallet::forUser($pdo, $userId);
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $history = Wallet::history($pdo, $wallet['id'], $page);
    $isEarner = $role === 'earner';
    $recent = $isEarner ? Withdrawals::forUser($pdo, $userId, 5) : PaymentOrders::recentForUser($pdo, $userId, 5);
    $bank = $isEarner ? BankAccounts::get($pdo, $userId) : null;
} catch (Throwable $e) {
    Logger::error('wallet.load_failed', ['exception' => get_class($e)]);
    yolotask_error_page(500);
}
$configured = AfrstarClient::isConfigured();
ob_start();
?>
<div class="wallet-grid">
  <?php if (!$configured): ?><?= yolotask_payments_unavailable_card($isEarner ? 'Withdrawals' : 'Wallet top-ups') ?><?php endif; ?>

  <section class="card card-glow dash-hero" aria-labelledby="balance-title">
    <p class="text-xs fw-bold text-primary" id="balance-title"><?= $isEarner ? 'AVAILABLE TO WITHDRAW' : 'AVAILABLE TO SPEND' ?></p>
    <div class="wallet-balance"><span class="amount" data-balance><?= h(yolotask_money($wallet['available_kobo'])) ?></span></div>
    <div class="wallet-sub">
      <span>On hold <strong><?= h(yolotask_money($wallet['held_kobo'])) ?></strong></span>
      <span>Currency <strong>NGN</strong></span>
    </div>
    <div class="hero-actions">
      <?php if ($isEarner): ?>
        <a class="btn btn-primary btn-sm" href="withdraw.php">Withdraw</a>
        <a class="btn btn-secondary btn-sm" href="bank-account.php"><?= $bank ? 'Bank account' : 'Add bank account' ?></a>
      <?php else: ?>
        <a class="btn btn-primary btn-sm" href="topup.php">Top up wallet</a>
      <?php endif; ?>
    </div>
  </section>

  <section class="card" aria-labelledby="recent-title">
    <div class="card-header"><h2 id="recent-title"><?= $isEarner ? 'Withdrawals' : 'Top-ups' ?></h2><span class="badge badge-info">Latest 5</span></div>
    <?php if ($recent === []): ?>
      <div class="empty-state"><p class="text-faint"><?= $isEarner ? 'No withdrawals yet. Approved task earnings show up in your balance.' : 'No top-ups yet. Fund your wallet to launch campaigns.' ?></p></div>
    <?php else: ?>
      <ul class="tx-list">
        <?php foreach ($recent as $r): ?>
          <li class="tx-row">
            <span class="tx-icon"><?= yolotask_nav_icon($isEarner ? 'logout' : 'login') ?></span>
            <div class="tx-meta">
              <?php if ($isEarner): ?>
                <strong>To <?= h((string) $r['bank_name']) ?> ••••<?= h(substr((string) $r['account_number'], -4)) ?></strong>
                <span class="text-xs text-faint"><?= h(yolotask_wat((string) $r['created_at'])) ?><?= $r['status'] === 'rejected' && $r['reject_reason'] ? ' · ' . h((string) $r['reject_reason']) : '' ?></span>
              <?php else: ?>
                <strong><a href="topup-return.php?o=<?= h(rawurlencode((string) $r['order_sn'])) ?>">Top-up <?= h((string) $r['order_sn']) ?></a></strong>
                <span class="text-xs text-faint"><?= h(yolotask_wat((string) $r['created_at'])) ?></span>
              <?php endif; ?>
            </div>
            <div class="tx-amount"><span><?= h(yolotask_money($isEarner ? $r['amount_kobo'] : ($r['credited_kobo'] ?? $r['amount_kobo']))) ?></span><?= yolotask_money_badge((string) $r['status']) ?></div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <section class="card" id="history" aria-labelledby="history-title">
    <div class="card-header"><h2 id="history-title">History</h2><span class="badge badge-info"><?= number_format($history['total']) ?> entr<?= $history['total'] === 1 ? 'y' : 'ies' ?></span></div>
    <?php if ($history['rows'] === []): ?>
      <div class="empty-state"><p class="text-faint">Every change to your balance will be listed here.</p></div>
    <?php else: ?>
      <ul class="tx-list">
        <?php foreach ($history['rows'] as $e): $in = (string) $e['direction'] === 'credit'; ?>
          <li class="tx-row">
            <span class="tx-icon"><?= yolotask_nav_icon($in ? 'login' : 'logout') ?></span>
            <div class="tx-meta"><strong><?= h(Wallet::entryLabel($e)) ?></strong><span class="text-xs text-faint"><?= h(yolotask_wat((string) $e['created_at'])) ?></span></div>
            <div class="tx-amount"><span class="<?= $in ? 'tx-in' : 'tx-out' ?>"><?= $in ? '+' : '−' ?><?= h(yolotask_money($e['amount_kobo'])) ?></span><span class="text-xs text-faint">Balance <?= h(yolotask_money($e['balance_after_kobo'])) ?></span></div>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php if ($history['pages'] > 1): ?>
        <nav class="flex-between pager" aria-label="Pages">
          <?php if ($history['page'] > 1): ?><a class="btn btn-ghost btn-sm" href="wallet.php?page=<?= $history['page'] - 1 ?>#history">Newer</a><?php else: ?><span></span><?php endif; ?>
          <span class="text-xs text-faint">Page <?= $history['page'] ?> of <?= $history['pages'] ?></span>
          <?php if ($history['page'] < $history['pages']): ?><a class="btn btn-ghost btn-sm" href="wallet.php?page=<?= $history['page'] + 1 ?>#history">Older</a><?php else: ?><span></span><?php endif; ?>
        </nav>
      <?php endif; ?>
    <?php endif; ?>
  </section>
</div>
<?php
yolotask_render_app($user, 'Wallet', (string) ob_get_clean(), 'wallet');
