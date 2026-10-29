<?php
declare(strict_types=1);

use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Money\Ledger;
use YoloTask\Money\Wallet;
use YoloTask\Payments\AfrstarClient;
use YoloTask\Security\Csrf;

require_once __DIR__ . '/money-helpers.php';

if (yolotask_role() !== 'admin') yolotask_error_page(404);
yolotask_private_headers();
try {
    $pdo = Database::connection();
    $admin = yolotask_require_user($pdo);
} catch (Throwable $e) {
    Logger::error('admin.wallets_load_failed', ['exception' => get_class($e)]);
    yolotask_error_page(500);
}
$adminId = (int) $admin['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $back = 'wallets.php' . (is_string($_POST['back'] ?? null) && str_starts_with($_POST['back'], '?') ? $_POST['back'] : '');
    yolotask_admin_csrf_or_back($back);
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'adjust') {
            $amount = yolotask_parse_naira(is_string($_POST['amount'] ?? null) ? $_POST['amount'] : '');
            if ($amount === null) throw new RuntimeException('Enter an amount in naira.');
            Wallet::adjust($pdo, $adminId, (int) ($_POST['user_id'] ?? 0), (string) ($_POST['direction'] ?? ''), $amount, (string) ($_POST['reason'] ?? ''), (string) ($_POST['idem'] ?? ''));
            yolotask_flash('Adjustment posted to the ledger.');
        } elseif ($action === 'reconcile') {
            $problems = Ledger::reconcile($pdo);
            yolotask_flash($problems === [] ? 'Reconciliation passed: every balance matches the ledger.' : 'Reconciliation found ' . count($problems) . ' problem(s): ' . $problems[0], $problems === [] ? 'success' : 'error');
        }
    } catch (RuntimeException $e) {
        yolotask_flash($e->getMessage(), 'error');
    } catch (Throwable $e) {
        Logger::error('admin.wallets_action_failed', ['action' => $action, 'exception' => get_class($e)]);
        yolotask_flash('Something went wrong. Please try again.', 'error');
    }
    yolotask_redirect($back);
}

$q = trim(is_string($_GET['q'] ?? null) ? mb_substr($_GET['q'], 0, 100) : '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$result = Wallet::search($pdo, $q, $page);
$totals = Wallet::totals($pdo);
$afrBalance = null;
$afrError = null;
if (AfrstarClient::isConfigured()) {
    try {
        $afrBalance = AfrstarClient::balance(5);
    } catch (Throwable $e) {
        $afrError = 'Unavailable right now';
    }
} else {
    $afrError = 'Not connected';
}
$csrf = Csrf::token();
$query = static fn (array $over): string => '?' . http_build_query(array_filter(array_merge(['q' => $q, 'page' => $page], $over), static fn ($v) => $v !== '' && $v !== null));
ob_start();
?>
<?= yolotask_admin_money_tabs($pdo, 'wallets') ?>
<section class="grid grid-4 dash-stats money-stats" aria-label="Money totals">
  <article class="stat-card"><span class="stat-icon"><?= yolotask_nav_icon('wallet') ?></span><span class="stat-label">User balances</span><span class="stat-value"><?= h(yolotask_money($totals['available'])) ?></span><span class="text-xs text-faint"><?= number_format($totals['wallets']) ?> wallets</span></article>
  <article class="stat-card stat-warning"><span class="stat-icon"><?= yolotask_nav_icon('refund') ?></span><span class="stat-label">On hold</span><span class="stat-value"><?= h(yolotask_money($totals['held'])) ?></span><span class="text-xs text-faint">Open withdrawals</span></article>
  <article class="stat-card"><span class="stat-icon"><?= yolotask_nav_icon('chart') ?></span><span class="stat-label">AfrStar clearing</span><span class="stat-value"><?= h(yolotask_money($totals['clearing'])) ?></span><span class="text-xs text-faint">Ledger: top-ups in minus payouts</span></article>
  <article class="stat-card"><span class="stat-icon"><?= yolotask_nav_icon('shield') ?></span><span class="stat-label">AfrStar balance</span><span class="stat-value" data-afrstar-balance><?= $afrBalance !== null ? h(yolotask_money($afrBalance)) : '—' ?></span><span class="text-xs text-faint"><?= $afrError !== null ? h($afrError) : 'Live from AfrStar' ?></span></article>
</section>

<section class="card" aria-labelledby="recon-title">
  <div class="card-header"><h2 id="recon-title">Reconciliation</h2><span class="badge badge-info">Also runs daily</span></div>
  <p class="text-sm text-faint">Checks that every transaction balances, that cached wallet balances equal the sum of their ledger entries, and that money on hold equals open withdrawals.</p>
  <form method="post" action="wallets.php"><input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="reconcile"><button class="btn btn-secondary" type="submit">Run reconciliation now</button></form>
</section>

<section class="card" aria-labelledby="wallets-title">
  <div class="card-header"><h2 id="wallets-title">User wallets</h2><span class="badge badge-info"><?= number_format($result['total']) ?> users</span></div>
  <form method="get" action="wallets.php" class="inline-form" style="margin-bottom:1rem">
    <input class="input" type="search" name="q" value="<?= h($q) ?>" placeholder="Search name or email" aria-label="Search wallets">
    <button class="btn btn-primary" type="submit">Search</button>
  </form>
  <?php if ($result['rows'] === []): ?>
    <div class="empty-state"><p class="text-faint">No earner or advertiser accounts match.</p></div>
  <?php else: ?>
    <ul class="user-list">
      <?php foreach ($result['rows'] as $u): ?>
        <li class="user-row">
          <div class="user-meta">
            <strong><?= h((string) $u['display_name']) ?></strong>
            <span class="text-xs text-faint text-break"><?= h((string) $u['email']) ?></span>
            <span class="user-tags">
              <span class="badge badge-info"><?= h(ucfirst((string) $u['role'])) ?></span>
              <span class="badge badge-success">Available <?= h(yolotask_money($u['available_kobo'])) ?></span>
              <?php if ((int) $u['held_kobo'] > 0): ?><span class="badge badge-warning">On hold <?= h(yolotask_money($u['held_kobo'])) ?></span><?php endif; ?>
            </span>
            <details class="danger-details" style="margin-top:8px">
              <summary class="btn btn-ghost btn-sm">Adjust balance</summary>
              <form method="post" action="wallets.php" class="stack">
                <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="adjust"><input type="hidden" name="back" value="<?= h($query([])) ?>">
                <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>"><input type="hidden" name="idem" value="<?= h(bin2hex(random_bytes(16))) ?>">
                <div class="inline-form">
                  <select class="input" name="direction" aria-label="Direction"><option value="credit">Credit (add)</option><option value="debit">Debit (remove)</option></select>
                  <input class="input" name="amount" inputmode="decimal" placeholder="Amount ₦" required aria-label="Amount">
                </div>
                <input class="input" name="reason" maxlength="200" minlength="5" required placeholder="Reason (required, kept in the ledger)" aria-label="Reason">
                <button class="btn btn-secondary btn-sm" type="submit" data-confirm="Post this adjustment to the ledger?">Post adjustment</button>
              </form>
            </details>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
    <?= yolotask_pager($result, static fn (int $p): string => 'wallets.php' . $query(['page' => $p])) ?>
  <?php endif; ?>
</section>
<?php
yolotask_render_app($admin, 'Money', (string) ob_get_clean(), 'money');
