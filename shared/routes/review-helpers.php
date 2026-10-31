<?php
declare(strict_types=1);

/** View helpers for the reviewer queues and the admin appeals pages (Phase 5). */

/** Reviewer portal sub-navigation with live counts. */
function yolotask_reviewer_tabs(PDO $pdo, string $active): string
{
    $appeals = 0;
    $stale = 0;
    try {
        $appeals = \YoloTask\Tasks\Appeals::openCount($pdo);
        $stale = \YoloTask\Tasks\StaleQueue::count($pdo);
    } catch (Throwable) {
    }
    $tabs = [
        'appeals' => ['Appeals', 'appeals.php', $appeals],
        'stale' => ['Stale proofs', 'stale.php', $stale],
        'history' => ['My decisions', 'history.php', 0],
    ];
    $html = '<nav class="card money-tabs" aria-label="Review queues">';
    foreach ($tabs as $key => [$label, $url, $count]) {
        $html .= '<a href="' . h($url) . '"' . ($key === $active ? ' class="active" aria-current="page"' : '') . '>' . h($label)
            . ($count > 0 ? ' <span class="badge badge-warning">' . (int) $count . '</span>' : '') . '</a>';
    }
    return $html . '</nav>';
}

/** Who made a rejection, in words. */
function yolotask_rejection_source(string $source): string
{
    return match ($source) {
        'advertiser' => 'the advertiser',
        'admin' => 'a YOLOTASK admin',
        'reviewer' => 'a YOLOTASK reviewer',
        'appeal' => 'appeal',
        default => 'the system',
    };
}

/** Badge for an appeal status. */
function yolotask_appeal_badge(array $a): string
{
    [$label, $class] = \YoloTask\Tasks\Appeals::statusLabel($a);
    return '<span class="badge ' . $class . '">' . h($label) . '</span>';
}

/** One line describing how an overturn was paid. */
function yolotask_appeal_funding(array $a): string
{
    if ((string) $a['status'] !== 'overturned') return '';
    if ((int) $a['over_budget'] !== 1) return 'Paid from campaign escrow';
    $parts = [];
    if ((int) $a['escrow_kobo'] > 0) $parts[] = yolotask_money($a['escrow_kobo']) . ' from escrow';
    if ((int) $a['wallet_kobo'] > 0) $parts[] = yolotask_money($a['wallet_kobo']) . ' from the advertiser wallet';
    if ((int) $a['platform_kobo'] > 0) $parts[] = yolotask_money($a['platform_kobo']) . ' fronted by YOLOTASK (advertiser debt)';
    return 'Over budget: ' . implode(', ', $parts);
}
