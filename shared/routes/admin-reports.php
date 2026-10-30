<?php
declare(strict_types=1);

use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Security\Audit;

require_once __DIR__ . '/money-helpers.php';

/* Admin: daily money report (Lagos days) and CSV export of ledger transactions. */
if (yolotask_role() !== 'admin') yolotask_error_page(404);
yolotask_private_headers();
try {
    $pdo = Database::connection();
    $admin = yolotask_require_user($pdo);
} catch (Throwable $e) {
    Logger::error('admin.reports_load_failed', ['exception' => get_class($e)]);
    yolotask_error_page(500);
}
$tz = new DateTimeZone('Africa/Lagos');
$parse = static function (string $key, string $default) use ($tz): DateTimeImmutable {
    $v = is_string($_GET[$key] ?? null) ? $_GET[$key] : '';
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v, $tz);
    return ($d !== false && $d->format('Y-m-d') === $v) ? $d : new DateTimeImmutable($default, $tz);
};
$to = $parse('to', 'today');
$from = $parse('from', 'today -29 days');
if ($from > $to) [$from, $to] = [$to, $from];
if ($from < $to->modify('-366 days')) $from = $to->modify('-366 days');
$fromUtc = $from->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$toUtc = $to->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

if (($_GET['export'] ?? '') === 'csv') {
    Audit::record($pdo, (int) $admin['id'], 'admin.ledger_exported', 'ledger', null);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="yolotask-ledger-' . $from->format('Ymd') . '-' . $to->format('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['transaction_id', 'time_wat', 'type', 'description', 'reference_type', 'reference_id', 'account', 'direction', 'amount_naira', 'balance_after_naira'], ',', '"', '');
    $stmt = $pdo->prepare('SELECT t.id, t.created_at, t.type, t.description, t.reference_type, t.reference_id, a.code, e.direction, e.amount_kobo, e.balance_after_kobo
        FROM ledger_transactions t JOIN ledger_entries e ON e.transaction_id = t.id JOIN ledger_accounts a ON a.id = e.account_id
        WHERE t.created_at >= :f AND t.created_at < :t ORDER BY t.id, e.id');
    $stmt->execute(['f' => $fromUtc, 't' => $toUtc]);
    while ($r = $stmt->fetch()) {
        $desc = (string) $r['description'];
        if ($desc !== '' && in_array($desc[0], ['=', '+', '-', '@'], true)) $desc = "'" . $desc; // no spreadsheet formulas
        fputcsv($out, [$r['id'], yolotask_wat((string) $r['created_at'], 'Y-m-d H:i:s'), $r['type'], $desc, $r['reference_type'], $r['reference_id'], $r['code'], $r['direction'],
            \YoloTask\Money\Money::decimal((int) $r['amount_kobo']), \YoloTask\Money\Money::decimal((int) $r['balance_after_kobo'])], ',', '"', '');
    }
    fclose($out);
    exit;
}

// Daily totals by transaction type (amount = transaction total), Lagos calendar days.
$stmt = $pdo->prepare("SELECT DATE(CONVERT_TZ(created_at, '+00:00', '+01:00')) d, type, COUNT(*) n, SUM(amount_kobo) amt FROM ledger_transactions
    WHERE created_at >= :f AND created_at < :t GROUP BY d, type ORDER BY d DESC");
$stmt->execute(['f' => $fromUtc, 't' => $toUtc]);
$cols = ['topup' => 'Top-ups', 'withdrawal_paid' => 'Paid out', 'campaign_fund' => 'Campaigns funded', 'campaign_refund' => 'Campaign refunds', 'task_reward' => 'Tasks approved'];
$days = [];
$totals = array_fill_keys(array_keys($cols), 0);
foreach ($stmt->fetchAll() ?: [] as $r) {
    $type = (string) $r['type'] === 'topup_review' ? 'topup' : (string) $r['type'];
    if (!isset($cols[$type])) continue;
    $days[(string) $r['d']][$type] = ($days[(string) $r['d']][$type] ?? 0) + (int) $r['amt'];
    $totals[$type] += (int) $r['amt'];
}
$rev = $pdo->prepare("SELECT COALESCE(SUM(e.amount_kobo), 0) FROM ledger_entries e JOIN ledger_accounts a ON a.id = e.account_id AND a.code = 'system:revenue' WHERE e.direction = 'credit' AND e.created_at >= :f AND e.created_at < :t");
$rev->execute(['f' => $fromUtc, 't' => $toUtc]);
$revenue = (int) $rev->fetchColumn();
$qs = http_build_query(['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')]);
ob_start();
?>
<?= yolotask_admin_money_tabs($pdo, 'reports') ?>
<section class="grid grid-4 dash-stats money-stats" aria-label="Period totals">
  <article class="stat-card stat-success"><span class="stat-label">Top-ups</span><span class="stat-value"><?= h(yolotask_money($totals['topup'])) ?></span><span class="text-xs text-faint">Credited to advertisers</span></article>
  <article class="stat-card"><span class="stat-label">Paid out</span><span class="stat-value"><?= h(yolotask_money($totals['withdrawal_paid'])) ?></span><span class="text-xs text-faint">Withdrawals confirmed by AfrStar</span></article>
  <article class="stat-card"><span class="stat-label">Tasks approved</span><span class="stat-value"><?= h(yolotask_money($totals['task_reward'])) ?></span><span class="text-xs text-faint">Advertiser spend on tasks</span></article>
  <article class="stat-card stat-success"><span class="stat-label">Task revenue</span><span class="stat-value" data-revenue><?= h(yolotask_money($revenue)) ?></span><span class="text-xs text-faint">Margins kept by YOLOTASK</span></article>
</section>
<section class="card" aria-labelledby="rp-title">
  <div class="card-header"><h2 id="rp-title">Daily money report</h2><a class="btn btn-secondary btn-sm" href="reports.php?<?= h($qs) ?>&amp;export=csv">Export ledger CSV</a></div>
  <form method="get" action="reports.php" class="inline-form" style="margin-bottom:.75rem">
    <input class="input" type="date" name="from" value="<?= h($from->format('Y-m-d')) ?>" aria-label="From (WAT)">
    <input class="input" type="date" name="to" value="<?= h($to->format('Y-m-d')) ?>" aria-label="To (WAT)">
    <button class="btn btn-secondary btn-sm" type="submit">Show</button>
  </form>
  <?php if ($days === []): ?>
    <div class="empty-state"><p class="text-faint">No money moved in this period.</p></div>
  <?php else: ?>
    <div class="table-wrap"><table class="table">
      <thead><tr><th scope="col">Day (WAT)</th><?php foreach ($cols as $label): ?><th scope="col"><?= h($label) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
        <?php foreach ($days as $d => $vals): ?><tr><td><?= h((string) $d) ?></td><?php foreach (array_keys($cols) as $k): ?><td><?= isset($vals[$k]) ? h(yolotask_money($vals[$k])) : '—' ?></td><?php endforeach; ?></tr><?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
  <p class="text-xs text-faint">Amounts are ledger transaction totals per Lagos day. The CSV lists every ledger entry (both sides of each transaction) in the period.</p>
</section>
<?php
yolotask_render_app($admin, 'Money reports', (string) ob_get_clean(), 'money');
