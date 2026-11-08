<?php
declare(strict_types=1);

/**
 * One-shot (safe to run again): retire premium ("targeted") campaigns.
 * Run once after deploying the release with migration 0017.
 *
 * For every campaign still tier = 'targeted' that is not finished (draft,
 * pending_approval, live, paused):
 *  - the campaign becomes standard (tier = 'basic') at its task type's
 *    current catalog price and reward (task_types.base_*);
 *  - a draft is simply re-quoted (no money was locked);
 *  - a funded campaign keeps the old price for work already in flight
 *    (pending proofs, and active reservations, which are stamped with it)
 *    and refunds the advertiser (old price - new price) for every slot
 *    nobody has taken yet, from the campaign escrow to the advertiser wallet
 *    (ledger key retire-premium:<campaign id>), capped so the escrow keeps
 *    enough for everything still owed (see Campaigns\Repricing);
 *  - the advertiser gets an in-app notification.
 * Custom tasks (migration 0016): a campaign on the 'custom' task type is
 * priced by its advertiser and premium never changed that price, so a premium
 * one is only switched to standard (its price, reward and escrow stay; no
 * refund). A premium campaign on the 'custom' platform with a listed task type
 * is repriced like any other. Standard campaigns, custom or not, are never
 * selected.
 * Finished campaigns (completed, cancelled, ended, rejected) are left as they
 * are. Each campaign is one transaction; a campaign already converted is no
 * longer 'targeted', and the ledger key is unique, so a second run changes
 * nothing. Interest data and the premium columns are left in the database,
 * unused.
 *
 * Usage: php scripts/retire-premium.php [--config-dir=/path/to/config-private] [--dry-run]
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$opts = getopt('', ['config-dir:', 'dry-run']);
if (isset($opts['config-dir'])) putenv('YOLOTASK_CONFIG_DIR=' . $opts['config-dir']);
define('YOLOTASK_APP_ROLE', 'cron');
define('YOLOTASK_APP_ROOT', dirname(__DIR__));
define('YOLOTASK_NO_SESSION', true);
require YOLOTASK_APP_ROOT . '/shared/bootstrap.php';

use YoloTask\Campaigns\Repricing;
use YoloTask\Core\Logger;
use YoloTask\Messaging\Notifications;
use YoloTask\Money\Money;

$pdo = \YoloTask\Core\Database::connection();
$dry = isset($opts['dry-run']);
if (!(bool) $pdo->query("SHOW COLUMNS FROM campaigns LIKE 'repriced_at'")->fetchColumn()) {
    fwrite(STDERR, "retire-premium: migration 0017_retire_premium.sql is not applied yet. Run scripts/migrate.php first.\n");
    exit(1);
}

$ids = $pdo->query("SELECT id FROM campaigns WHERE tier = 'targeted' AND status IN ('draft', 'pending_approval', 'live', 'paused') ORDER BY id")->fetchAll(PDO::FETCH_COLUMN) ?: [];
$typeStmt = $pdo->prepare('SELECT t.slug, t.base_price_kobo, t.base_reward_kobo, c.unit_price_kobo, c.unit_reward_kobo FROM campaigns c JOIN task_types t ON t.id = c.task_type_id WHERE c.id = :id');
$done = 0;
$failed = 0;
$total = 0;
foreach ($ids as $id) {
    $id = (int) $id;
    try {
        $typeStmt->execute(['id' => $id]);
        $base = $typeStmt->fetch();
        if (!is_array($base)) throw new RuntimeException('task type missing');
        // Custom task type: the advertiser's own price stays (Repricing leaves an unchanged price alone).
        $customPrice = (string) $base['slug'] === \YoloTask\Campaigns\Pricing::CUSTOM_SLUG;
        $newPrice = $customPrice ? (int) $base['unit_price_kobo'] : (int) $base['base_price_kobo'];
        $newReward = $customPrice ? (int) $base['unit_reward_kobo'] : (int) $base['base_reward_kobo'];
        $r = Repricing::reprice($pdo, $id, $newPrice, $newReward, 'retire-premium:' . $id,
            'moved to standard pricing, refund for untaken slots', null,
            static function (PDO $pdo, array $out) use ($id): void {
                // Converted in the same transaction as the refund: a second run no longer selects it.
                $pdo->prepare("UPDATE campaigns SET tier = 'basic' WHERE id = :id AND tier = 'targeted'")->execute(['id' => $id]);
            }, $dry);
    } catch (Throwable $e) {
        $failed++;
        Logger::error('retire_premium.failed', ['campaign_id' => $id, 'exception' => get_class($e), 'message' => $e->getMessage()]);
        fwrite(STDERR, "campaign #{$id}: FAILED: " . $e->getMessage() . "\n");
        continue;
    }
    $done++;
    $total += $r['refund'];
    fwrite(STDOUT, sprintf("campaign #%d (%s%s): %s %s -> %s per task, %d untaken slot(s), refund %s\n", $id, $r['status'], $customPrice ? ', custom task' : '', $dry ? 'would convert' : 'converted',
        Money::format($r['old_price']), Money::format($r['price']), $r['untaken'], Money::format($r['refund'])));
    if ($dry) continue;

    $c = $r['campaign'];
    $title = mb_substr((string) $c['title'], 0, 80);
    if ($customPrice) {
        $body = 'Premium targeting has been retired; your campaign now runs as standard at your own price of ' . Money::format($r['price']) . ' per task. Nothing else changes.';
    } elseif ($r['status'] === 'draft') {
        $body = 'Premium targeting has been retired; your draft now runs as standard at ' . Money::format($r['price']) . ' per task.';
    } elseif ($r['refund'] > 0) {
        $body = 'Premium targeting has been retired; your campaign now runs as standard and ' . Money::format($r['refund']) . ' was refunded to your wallet.';
    } else {
        $body = 'Premium targeting has been retired; your campaign now runs as standard. No refund was due' . ($r['repriced'] && $r['untaken'] === 0 ? ' because every slot was already taken or in progress.' : '.');
    }
    Notifications::notify($pdo, (int) $c['advertiser_user_id'], 'campaigns', 'Campaign now standard: ' . $title, $body, 'campaign.php?id=' . $id, 'retire-premium:' . $id);
}
fwrite(STDOUT, sprintf("retire-premium: %s %d campaign(s), refunded %s in total%s.\n", $dry ? 'dry run, would convert' : 'converted', $done, Money::format($total), $failed > 0 ? ", {$failed} failed (see above)" : ''));
exit($failed > 0 ? 1 : 0);
