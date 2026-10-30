<?php
declare(strict_types=1);

use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Money\Wallet;

require_once __DIR__ . '/money-helpers.php';

/*
 * Wallet receipt (earner and advertiser): one entry on the user's own
 * wallet, with what it was for. receipt.php?id=<ledger entry id>. Entries on
 * other wallets give the same 404 as missing ones.
 */
if (!in_array(yolotask_role(), ['earner', 'advertiser'], true)) yolotask_error_page(404);
yolotask_private_headers();
try {
    $pdo = Database::connection();
    $user = yolotask_require_user($pdo);
    $wallet = Wallet::forUser($pdo, (int) $user['id']);
    $stmt = $pdo->prepare("SELECT e.id, e.direction, e.amount_kobo, e.balance_after_kobo, e.created_at, a.kind, t.id tx_id, t.type, t.description, t.reference_type, t.reference_id, t.idempotency_key
        FROM ledger_entries e JOIN ledger_accounts a ON a.id = e.account_id JOIN ledger_transactions t ON t.id = e.transaction_id
        WHERE e.id = :id AND a.wallet_id = :w AND a.kind = 'wallet_available'");
    $stmt->execute(['id' => (int) ($_GET['id'] ?? 0), 'w' => $wallet['id']]);
    $e = $stmt->fetch();
} catch (Throwable $ex) {
    Logger::error('receipt.load_failed', ['exception' => get_class($ex)]);
    yolotask_error_page(500);
}
if (!is_array($e)) yolotask_error_page(404);

$details = [];
try {
    $ref = (string) ($e['reference_type'] ?? '');
    $rid = (int) ($e['reference_id'] ?? 0);
    if ($ref === 'payment_order') {
        $q = $pdo->prepare('SELECT order_sn, platform_osn, provider_reference, amount_kobo, paid_at FROM payment_orders WHERE id = :id AND user_id = :u');
        $q->execute(['id' => $rid, 'u' => $user['id']]);
        if ($o = $q->fetch()) $details = ['Order' => $o['order_sn'], 'AfrStar reference' => $o['platform_osn'] ?: '—', 'Bank reference' => $o['provider_reference'] ?: '—', 'Paid' => yolotask_wat((string) $o['paid_at']) . ' WAT'];
    } elseif ($ref === 'withdrawal') {
        $q = $pdo->prepare('SELECT id, order_sn, bank_name, account_last4, account_name, status, provider_reference, created_at, completed_at FROM withdrawals WHERE id = :id AND user_id = :u');
        $q->execute(['id' => $rid, 'u' => $user['id']]);
        if ($w = $q->fetch()) $details = ['Withdrawal' => '#' . $w['id'], 'To' => $w['bank_name'] . ' ••••' . ($w['account_last4'] ?? '') . ' · ' . $w['account_name'], 'Status' => yolotask_money_status((string) $w['status'])[0], 'Payout reference' => $w['order_sn'] ?: '—', 'Bank reference' => $w['provider_reference'] ?: '—'];
    } elseif ($ref === 'campaign') {
        $q = $pdo->prepare('SELECT id, title, quantity, unit_price_kobo FROM campaigns WHERE id = :id AND advertiser_user_id = :u');
        $q->execute(['id' => $rid, 'u' => $user['id']]);
        if ($c = $q->fetch()) $details = ['Campaign' => '#' . $c['id'] . ' · ' . $c['title'], 'Pricing' => number_format((int) $c['quantity']) . ' × ' . yolotask_money($c['unit_price_kobo'])];
    } elseif ($ref === 'submission') {
        $q = $pdo->prepare('SELECT s.id, c.title, s.decided_at, s.decision_source FROM submissions s JOIN campaigns c ON c.id = s.campaign_id WHERE s.id = :id AND s.earner_user_id = :u');
        $q->execute(['id' => $rid, 'u' => $user['id']]);
        if ($s = $q->fetch()) $details = ['Task' => $s['title'], 'Submission' => '#' . $s['id'], 'Approved' => yolotask_wat((string) $s['decided_at']) . ' WAT' . ($s['decision_source'] === 'auto' ? ' (automatically)' : '')];
    }
} catch (Throwable $ex) {
    Logger::error('receipt.details_failed', ['exception' => get_class($ex)]);
}
$in = (string) $e['direction'] === 'credit';
ob_start();
?>
<div class="wallet-grid receipt">
  <section class="card card-glow" aria-labelledby="rc-title">
    <p class="text-xs fw-bold text-primary">YOLOTASK RECEIPT</p>
    <h2 id="rc-title"><?= h(Wallet::entryLabel($e)) ?></h2>
    <div class="wallet-balance"><span class="amount"><?= $in ? '+' : '−' ?><?= h(yolotask_money($e['amount_kobo'])) ?></span></div>
    <dl class="receipt-list">
      <div><dt>Date</dt><dd><?= h(yolotask_wat((string) $e['created_at'])) ?> WAT</dd></div>
      <div><dt>Account</dt><dd><?= h((string) $user['display_name']) ?> · <?= h((string) $user['email']) ?></dd></div>
      <?php foreach ($details as $k => $v): ?><div><dt><?= h($k) ?></dt><dd class="text-break"><?= h((string) $v) ?></dd></div><?php endforeach; ?>
      <div><dt>Balance after</dt><dd><?= h(yolotask_money($e['balance_after_kobo'])) ?></dd></div>
      <div><dt>Transaction</dt><dd class="mono">TX-<?= (int) $e['tx_id'] ?>-<?= (int) $e['id'] ?></dd></div>
    </dl>
    <div class="hero-actions no-print"><button class="btn btn-secondary btn-sm" type="button" onclick="window.print()">Print or save as PDF</button><a class="btn btn-ghost btn-sm" href="wallet.php#history">Back to wallet</a></div>
  </section>
</div>
<?php
yolotask_render_app($user, 'Receipt', (string) ob_get_clean(), 'wallet');
