<?php
declare(strict_types=1);
namespace YoloTask\Campaigns;

use PDO;
use RuntimeException;
use YoloTask\Money\Ledger;
use YoloTask\Money\Wallet;
use YoloTask\Security\Audit;

/**
 * Lower an existing campaign's per-task price and reward (migration 0017),
 * refunding the advertiser for the slots nobody has taken yet.
 *
 * Work already in flight keeps the price it was taken at: pending proofs
 * keep their own submissions.unit_price/unit_reward, and active, unexpired
 * reservations are stamped with the old price before the campaign changes,
 * so the earner who started the task is paid what was shown to them.
 * Rejected proofs that are resubmitted later take a fresh slot at the new
 * price (Tasks\Submissions::resubmit).
 *
 * Refund for a funded campaign (pending_approval, live, paused):
 *   untaken = quantity + extra_approved_count - approved - pending - active reservations
 *   refund  = min(untaken x (old price - new price),
 *                 unspent escrow - pending proofs' prices - reservations' prices - untaken x new price)
 *   floored at 0. The second term only bites when the escrow was already
 *   short of the full old price for every untaken slot (an appeal paid out
 *   of the budget), so the escrow always keeps enough for everything still
 *   owed. Ledger: Dr campaign escrow / Cr advertiser wallet (available),
 *   type campaign_refund, under the caller's idempotency key; refunded_kobo
 *   and a 'refund' funding event record it. budget_kobo keeps what was
 *   funded and repriced_at is set (chk_campaigns_money allows that).
 * A draft has no money in it: its price, reward and budget are re-quoted.
 * Closed campaigns are refused (their settle() already returned the rest).
 */
final class Repricing
{
    /**
     * @param callable(PDO,array):void|null $inTransaction extra writes made in the same transaction (after the reprice)
     * @return array{campaign:array,status:string,old_price:int,old_reward:int,price:int,reward:int,untaken:int,refund:int,repriced:bool,refund_tx:?int}
     */
    public static function reprice(PDO $pdo, int $campaignId, int $price, int $reward, string $idempotencyKey, string $note, ?int $actorId = null, ?callable $inTransaction = null, bool $dryRun = false): array
    {
        $owner = Campaigns::find($pdo, $campaignId);
        if ($owner === null) throw new RuntimeException('Campaign not found.');
        $advertiserId = (int) $owner['advertiser_user_id'];
        Wallet::forUser($pdo, $advertiserId);
        $pdo->beginTransaction();
        try {
            $wallet = Wallet::lock($pdo, $advertiserId);
            $c = Campaigns::lockRow($pdo, $campaignId);
            $status = (string) $c['status'];
            if ($status !== 'draft' && !in_array($status, Campaigns::OPEN, true)) throw new RuntimeException('Campaign #' . $campaignId . ' is closed.');
            $oldPrice = (int) $c['unit_price_kobo'];
            $oldReward = (int) $c['unit_reward_kobo'];
            $out = ['campaign' => $c, 'status' => $status, 'old_price' => $oldPrice, 'old_reward' => $oldReward, 'price' => $oldPrice, 'reward' => $oldReward,
                'untaken' => 0, 'refund' => 0, 'repriced' => false, 'refund_tx' => null];
            // Only a valid, lower price is applied; anything else leaves the price alone.
            $lower = $reward > 0 && $price > $reward && $price < $oldPrice;

            if ($status === 'draft') {
                if ($reward > 0 && $price > $reward) { // a draft is simply re-quoted at the new price
                    $pdo->prepare("UPDATE campaigns SET unit_price_kobo = :p, unit_reward_kobo = :r, budget_kobo = :b WHERE id = :id AND status = 'draft' AND funded_kobo = 0")
                        ->execute(['p' => $price, 'r' => $reward, 'b' => $price * (int) $c['quantity'], 'id' => $campaignId]);
                    $out['price'] = $price;
                    $out['reward'] = $reward;
                    $out['repriced'] = true;
                }
            } elseif ($lower) {
                // Stamp live reservations with the price they were started at (older rows have none).
                $pdo->prepare("UPDATE task_reservations SET unit_price_kobo = COALESCE(unit_price_kobo, :p), unit_reward_kobo = COALESCE(unit_reward_kobo, :r)
                    WHERE campaign_id = :c AND status = 'active' AND expires_at > UTC_TIMESTAMP()")
                    ->execute(['p' => $oldPrice, 'r' => $oldReward, 'c' => $campaignId]);
                $held = $pdo->prepare("SELECT COUNT(*) FROM task_reservations WHERE campaign_id = :c AND status = 'active' AND expires_at > UTC_TIMESTAMP()");
                $held->execute(['c' => $campaignId]);
                $reserved = (int) $held->fetchColumn();
                $untaken = max(0, Campaigns::capacity($c) - (int) $c['approved_count'] - (int) $c['pending_count'] - $reserved);
                $unspent = (int) $c['funded_kobo'] - (int) $c['spent_kobo'] - (int) $c['refunded_kobo'];
                $committed = Campaigns::pendingValue($pdo, $campaignId) + Campaigns::reservedValue($pdo, $c);
                $refund = max(0, min($untaken * ($oldPrice - $price), $unspent - $committed - $untaken * $price));
                if ($refund > 0) {
                    $tx = Ledger::post($pdo, $idempotencyKey, 'campaign_refund', 'Campaign #' . $campaignId . ' refund: ' . $note, [
                        [(int) $c['escrow_account_id'], 'debit', $refund],
                        [(int) $wallet['available_account_id'], 'credit', $refund],
                    ], 'campaign', $campaignId, $actorId);
                    if (!$tx['created']) throw new RuntimeException('Campaign #' . $campaignId . ' was already refunded under ' . $idempotencyKey . '.');
                    $pdo->prepare('UPDATE campaigns SET refunded_kobo = refunded_kobo + :r WHERE id = :id')->execute(['r' => $refund, 'id' => $campaignId]);
                    $pdo->prepare("INSERT INTO campaign_funding_events (campaign_id, event, amount_kobo, ledger_transaction_id, note) VALUES (:c, 'refund', :a, :t, :n)")
                        ->execute(['c' => $campaignId, 'a' => $refund, 't' => $tx['id'], 'n' => mb_substr($note, 0, 255)]);
                    $out['refund_tx'] = $tx['id'];
                }
                $pdo->prepare('UPDATE campaigns SET unit_price_kobo = :p, unit_reward_kobo = :r, repriced_at = UTC_TIMESTAMP() WHERE id = :id')
                    ->execute(['p' => $price, 'r' => $reward, 'id' => $campaignId]);
                $out['price'] = $price;
                $out['reward'] = $reward;
                $out['untaken'] = $untaken;
                $out['refund'] = $refund;
                $out['repriced'] = true;
            }
            if ($inTransaction !== null) $inTransaction($pdo, $out);
            if ($out['repriced']) Audit::record($pdo, $actorId, 'campaign.repriced', 'campaign', $campaignId);
            if ($dryRun) {
                $pdo->rollBack();
            } else {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $out;
    }
}
