<?php
declare(strict_types=1);

/** View helpers shared by the earner leaderboard and the admin leaders page (Phase 7). */

require_once __DIR__ . '/money-helpers.php';

/** Period + board toggles; $base is the page file name. */
function yolotask_leaderboard_toggles(string $base, string $board, string $period): string
{
    $url = static fn (string $b, string $p): string => $base . '?' . http_build_query(['board' => $b, 'period' => $p]);
    $html = '<nav class="card money-tabs lb-toggle" aria-label="Period">';
    foreach (['week' => 'This Week', 'month' => 'This Month', 'all' => 'All Time'] as $k => $label) {
        $html .= '<a href="' . h($url($board, $k)) . '"' . ($period === $k ? ' class="active" aria-current="page"' : '') . '>' . h($label) . '</a>';
    }
    $html .= '</nav><nav class="card money-tabs lb-toggle" aria-label="Board">';
    foreach (['workers' => 'Top Workers', 'referrers' => 'Top Referrers'] as $k => $label) {
        $html .= '<a href="' . h($url($k, $period)) . '"' . ($board === $k ? ' class="active" aria-current="page"' : '') . '>' . h($label) . '</a>';
    }
    return $html . '</nav>';
}

/** Rank cell: medal for the top 3, number otherwise, dash when unranked. */
function yolotask_leaderboard_rank(?int $rank): string
{
    if ($rank === null || $rank <= 0) return '<span class="lb-rank" aria-label="Not ranked">—</span>';
    $medals = [1 => 'gold', 2 => 'silver', 3 => 'bronze'];
    if (isset($medals[$rank])) {
        // Styled medal (no emoji, so it looks the same on every phone and font).
        return '<span class="lb-rank lb-medal lb-' . $medals[$rank] . '" aria-label="Rank ' . $rank . ', ' . $medals[$rank] . ' medal" title="' . ucfirst($medals[$rank]) . ' medal">' . $rank . '</span>';
    }
    return '<span class="lb-rank" aria-label="Rank ' . $rank . '">' . $rank . '</span>';
}

/** "12 tasks" / "3 referrals" */
function yolotask_leaderboard_count(string $board, int $count): string
{
    $unit = $board === 'referrers' ? 'referral' : 'task';
    return number_format($count) . ' ' . $unit . ($count === 1 ? '' : 's');
}

/** "This Week" / "This Month" / "All Time" */
function yolotask_leaderboard_period_label(string $period): string
{
    return ['week' => 'This Week', 'month' => 'This Month', 'all' => 'All Time'][$period] ?? 'This Week';
}
