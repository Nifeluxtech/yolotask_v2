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

/**
 * Subtle "YOLOTASK" source label on proofs and appeals of YOLOTASK tasks
 * (migration 0019). Staff only: earners never see where a task comes from.
 */
function yolotask_source_badge(array $row): string
{
    if (empty($row['is_platform']) || !in_array(yolotask_role(), ['reviewer', 'admin'], true)) return '';
    return '<span class="badge badge-source" data-source="yolotask" title="Task provided by YOLOTASK">YOLOTASK</span>';
}

/** One task in the earner feed (advertisers' tasks show the slots left; YOLOTASK tasks have no slot limit). */
function yolotask_feed_task_row(array $c): string
{
    $meta = h((string) $c['platform_name']) . ' · ' . h((string) $c['task_type_name']);
    if ((int) ($c['is_platform'] ?? 0) !== 1) {
        $left = \YoloTask\Campaigns\Campaigns::slotsLeft($c);
        $meta .= ' · ' . number_format($left) . ' slot' . ($left === 1 ? '' : 's') . ' left';
    }
    return '<li class="tx-row task-row" data-task="' . (int) $c['id'] . '">'
        . '<span class="tx-icon platform-icon" data-platform="' . h((string) $c['platform_slug']) . '">' . h(mb_substr((string) $c['platform_name'], 0, 1)) . '</span>'
        . '<div class="tx-meta"><strong><a href="task.php?id=' . (int) $c['id'] . '">' . h((string) $c['title']) . '</a></strong><span class="text-xs text-faint">' . $meta . '</span></div>'
        . '<div class="tx-amount"><span class="tx-in">+' . h(yolotask_money($c['unit_reward_kobo'])) . '</span><a class="btn btn-secondary btn-sm" href="task.php?id=' . (int) $c['id'] . '">View</a></div></li>';
}

/**
 * "Activate your account" card (feed, dashboard, task page) for earners who
 * must activate before they can access tasks. $offer comes from
 * Account\Activation::offer(); only shown while activation is required.
 */
function yolotask_activation_card(array $offer): string
{
    $fee = yolotask_money($offer['fee']);
    return '<section class="card activation-card" aria-labelledby="activation-card-title" data-activation-card>'
        . '<span class="stat-icon">' . yolotask_nav_icon('sparkle') . '</span>'
        . '<div class="activation-text"><h3 id="activation-card-title">Activate your account with ' . h($fee) . ' to access tasks.</h3>'
        . '<p class="text-sm text-faint">Pay once by bank transfer. Every task in your feed opens as soon as the payment is confirmed.</p></div>'
        . '<a class="btn btn-primary btn-sm" href="activate.php">Activate now</a></section>';
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
    $appeals = 0;
    $platform = 0;
    try {
        $queue = (int) $pdo->query("SELECT COUNT(*) FROM campaigns WHERE status = 'pending_approval'")->fetchColumn();
        $platform = (int) $pdo->query("SELECT COUNT(*) FROM submissions s JOIN campaigns c ON c.id = s.campaign_id WHERE c.is_platform = 1 AND s.status = 'pending'")->fetchColumn();
        $stale = \YoloTask\Tasks\StaleQueue::count($pdo);
        $appeals = \YoloTask\Tasks\Appeals::openCount($pdo);
    } catch (Throwable) {
    }
    $tabs = [
        'campaigns' => ['Campaigns', 'campaigns.php', $queue],
        'submissions' => ['Submissions', 'submissions.php', $stale],
        'appeals' => ['Appeals', 'appeals.php', $appeals],
        'platform' => ['YOLOTASK tasks', 'platform-tasks.php', $platform],
        'activation' => ['Account activation', 'activation.php', 0],
        'catalog' => ['Platforms & prices', 'catalog.php', 0],
        'settings' => ['Task settings', 'task-settings.php', 0],
        'levels' => ['Earner levels', 'levels.php', 0],
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

/** The campaign's task link if it is a plain http(s) URL, else null (never javascript:, data:, etc.). */
function yolotask_safe_task_url(?string $url): ?string
{
    $url = trim((string) $url);
    if ($url === '' || strlen($url) > 2000 || preg_match('/[\x00-\x20\x7f<>"\'\\\\]/', $url)) return null;
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) return null;
    return in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) ? $url : null;
}

/** "Open task link" (new tab) for reviewers comparing a proof with the task, or a muted placeholder. */
function yolotask_task_link(?string $url, string $label = 'Open task link'): string
{
    $safe = yolotask_safe_task_url($url);
    if ($safe === null) return '<span class="task-link text-xs text-faint">No task link</span>';
    return '<a class="task-link text-xs" href="' . h($safe) . '" target="_blank" rel="noopener noreferrer nofollow" title="' . h($safe) . '" aria-label="' . h($label) . ' (opens in a new tab)">' . h($label) . ' <span aria-hidden="true">↗</span></a>';
}

/** Proof thumbnail with the task link right beside it (submission and appeal lists). */
function yolotask_proof_cell(?int $proofId, ?string $taskUrl, string $label = 'Proof screenshot'): string
{
    return '<div class="proof-cell">' . yolotask_proof_thumb($proofId, $label) . yolotask_task_link($taskUrl) . '</div>';
}

function yolotask_post_str(string $key): string
{
    return is_string($_POST[$key] ?? null) ? $_POST[$key] : '';
}
