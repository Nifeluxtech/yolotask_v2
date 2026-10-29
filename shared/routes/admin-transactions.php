<?php
declare(strict_types=1);

use YoloTask\Core\Database;
use YoloTask\Core\Logger;

require_once __DIR__ . '/money-helpers.php';

if (yolotask_role() !== 'admin') yolotask_error_page(404);
yolotask_private_headers();
try {
    $pdo = Database::connection();
    $admin = yolotask_require_user($pdo);
} catch (Throwable $e) {
    Logger::error('admin.transactions_load_failed', ['exception' => get_class($e)]);
    yolotask_error_page(500);
}

$types = ['topup' => 'Top-ups', 'topup_review' => 'Reviewed top-ups', 'withdrawal_hold' => 'Withdrawal holds', 'withdrawal_release' => 'Releases', 'withdrawal_paid' => 'Payouts', 'adjustment' => 'Adjustments'];
$type = is_string($_GET['type'] ?? null) && isset($types[$_GET['type']]) ? $_GET['type'] : '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$where = $type !== '' ? ' WHERE t.type = :t' : '';
$args = $type !== '' ? ['t' => $type] : [];
$count = $pdo->prepare('SELECT COUNT(*) FROM ledger_transactions t' . $where);
$count->execute($args);
$total = (int) $count->fetchColumn();
$stmt = $pdo->prepare('SELECT t.*, u.display_name actor FROM ledger_transactions t LEFT JOIN users u ON u.id = t.created_by_user_id' . $where . ' ORDER BY t.id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage));
$stmt->execute($args);
$rows = $stmt->fetchAll() ?: [];
$entries = [];
if ($rows !== []) {
    $ids = array_map(static fn ($r) => (int) $r['id'], $rows);
    $in = implode(',', $ids);
    foreach ($pdo->query("SELECT e.transaction_id, e.direction, e.amount_kobo, a.code, a.name, u.email FROM ledger_entries e JOIN ledger_accounts a ON a.id = e.account_id LEFT JOIN wallets w ON w.id = a.wallet_id LEFT JOIN users u ON u.id = w.user_id WHERE e.transaction_id IN ({$in}) ORDER BY e.id") as $e) {
        $entries[(int) $e['transaction_id']][] = $e;
    }
}
$result = ['page' => $page, 'pages' => max(1, (int) ceil($total / $perPage)), 'total' => $total];
ob_start();
?>
<?= yolotask_admin_money_tabs($pdo, 'transactions') ?>
<section class="card" aria-labelledby="tx-title">
  <div class="card-header"><h2 id="tx-title">Ledger transactions</h2><span class="badge badge-info"><?= number_format($total) ?></span></div>
  <nav class="money-tabs" aria-label="Type filter" style="padding:0">
    <a href="transactions.php"<?= $type === '' ? ' class="active"' : '' ?>>All</a>
    <?php foreach ($types as $key => $label): ?><a href="transactions.php?type=<?= h($key) ?>"<?= $key === $type ? ' class="active"' : '' ?>><?= h($label) ?></a><?php endforeach; ?>
  </nav>
  <?php if ($rows === []): ?>
    <div class="empty-state"><p class="text-faint">No ledger transactions yet.</p></div>
  <?php else: ?>
    <ul class="tx-list">
      <?php foreach ($rows as $t): ?>
        <li class="tx-row"><div class="tx-meta">
          <strong>#<?= (int) $t['id'] ?> · <?= h($types[(string) $t['type']] ?? (string) $t['type']) ?> · <?= h(yolotask_money($t['amount_kobo'])) ?></strong>
          <span class="text-xs text-faint text-break"><?= h((string) $t['description']) ?> · <?= h(yolotask_wat((string) $t['created_at'])) ?><?= $t['actor'] ? ' · by ' . h((string) $t['actor']) : '' ?></span>
          <div class="table-wrap"><table class="table"><tbody>
            <?php foreach ($entries[(int) $t['id']] ?? [] as $e): ?>
              <tr><td class="text-xs"><?= h((string) $e['name']) ?><?= $e['email'] ? ' (' . h((string) $e['email']) . ')' : '' ?></td><td class="text-xs"><?= (string) $e['direction'] === 'debit' ? 'Debit' : 'Credit' ?></td><td class="text-xs"><?= h(yolotask_money($e['amount_kobo'])) ?></td></tr>
            <?php endforeach; ?>
          </tbody></table></div>
        </div></li>
      <?php endforeach; ?>
    </ul>
    <?= yolotask_pager($result, static fn (int $p): string => 'transactions.php?' . http_build_query(array_filter(['type' => $type, 'page' => $p]))) ?>
  <?php endif; ?>
</section>
<?php
yolotask_render_app($admin, 'Ledger', (string) ob_get_clean(), 'money');
