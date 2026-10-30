<?php
declare(strict_types=1);

/** View helpers shared by the campaign, task feed, submission and admin marketplace pages. */

require_once __DIR__ . '/money-helpers.php';

use YoloTask\Campaigns\Campaigns;
use YoloTask\Tasks\Submissions;

function yolotask_campaign_badge(string $status): string
{
    [$label, $class] = Campaigns::statusLabel($status);
    return '<span class="badge ' . $class . '">' . h($label) . '</span>';
}

function yolotask_submission_badge(array $s): string
{
    [$label, $class] = Submissions::statusLabel($s);
    return '<span class="badge ' . $class . '">' . h($label) . '</span>';
}

function yolotask_tier_badge(string $tier): string
{
    return $tier === 'targeted' ? '<span class="badge badge-premium">Premium</span>' : '';
}

/** Progress bar: approved of quantity (plus pending as a lighter segment). */
function yolotask_progress(int $approved, int $pending, int $quantity): string
{
    $q = max(1, $quantity);
    $a = min(100, round($approved * 100 / $q, 1));
    $p = min(100 - $a, round($pending * 100 / $q, 1));
    return '<div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="' . $quantity . '" aria-valuenow="' . $approved . '" aria-label="' . $approved . ' of ' . $quantity . ' approved">'
        . '<span class="progress-bar" style="width:' . $a . '%"></span><span class="progress-pending" style="width:' . $p . '%"></span></div>';
}

/** "in 3 h 20 min" / "2 days" style duration until a UTC time. */
function yolotask_until(?string $utc): string
{
    if ($utc === null || $utc === '') return '—';
    $secs = strtotime($utc . ' UTC') - time();
    if ($secs <= 0) return 'now';
    if ($secs < 3600) return max(1, intdiv($secs, 60)) . ' min';
    if ($secs < 86400) return intdiv($secs, 3600) . ' h ' . intdiv($secs % 3600, 60) . ' min';
    $d = intdiv($secs, 86400);
    return $d . ' day' . ($d === 1 ? '' : 's');
}

/** Admin marketplace sub-navigation. */
function yolotask_admin_market_tabs(PDO $pdo, string $active): string
{
    $queue = 0;
    $stale = 0;
    try {
        $queue = (int) $pdo->query("SELECT COUNT(*) FROM campaigns WHERE status = 'pending_approval'")->fetchColumn();
        $stale = \YoloTask\Tasks\StaleQueue::count($pdo);
    } catch (Throwable) {
    }
    $tabs = [
        'campaigns' => ['Campaigns', 'campaigns.php', $queue],
        'submissions' => ['Submissions', 'submissions.php', $stale],
        'catalog' => ['Platforms & prices', 'catalog.php', 0],
        'settings' => ['Task settings', 'task-settings.php', 0],
    ];
    $html = '<nav class="card money-tabs" aria-label="Marketplace sections">';
    foreach ($tabs as $key => [$label, $url, $count]) {
        $html .= '<a href="' . h($url) . '"' . ($key === $active ? ' class="active" aria-current="page"' : '') . '>' . h($label)
            . ($count > 0 ? ' <span class="badge badge-warning">' . (int) $count . '</span>' : '') . '</a>';
    }
    return $html . '</nav>';
}

/** Common POST guard for any portal: CSRF or bounce back with an error. */
function yolotask_csrf_or_back(string $back): void
{
    if (!\YoloTask\Security\Csrf::verify(is_string($_POST['_csrf'] ?? null) ? $_POST['_csrf'] : null)) {
        yolotask_flash('Your form expired. Please try again.', 'error');
        yolotask_redirect($back);
    }
}

/** Small proof thumbnail that opens the full image (served by proof.php after an access check). */
function yolotask_proof_thumb(?int $proofId, string $label = 'Proof screenshot'): string
{
    if ($proofId === null || $proofId <= 0) return '<span class="text-xs text-faint">No proof file</span>';
    $url = 'proof.php?id=' . $proofId;
    return '<a class="proof-thumb" href="' . h($url) . '" target="_blank" rel="noopener"><img src="' . h($url) . '" alt="' . h($label) . '" loading="lazy" decoding="async"></a>';
}

function yolotask_post_str(string $key): string
{
    return is_string($_POST[$key] ?? null) ? $_POST[$key] : '';
}
