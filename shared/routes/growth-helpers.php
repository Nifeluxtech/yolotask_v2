<?php
declare(strict_types=1);

/** View helpers for the Phase 6 admin growth pages (referrals, levels, interests, announcements). */

require_once __DIR__ . '/tasks-helpers.php';

function yolotask_admin_growth_tabs(PDO $pdo, string $active): string
{
    $flagged = 0;
    try {
        $flagged = (int) $pdo->query("SELECT COUNT(*) FROM referrals WHERE status = 'flagged'")->fetchColumn()
            + (int) $pdo->query("SELECT COUNT(*) FROM referral_payouts WHERE flagged_at IS NOT NULL AND status = 'paid'")->fetchColumn();
    } catch (Throwable) {
    }
    $tabs = [
        'referrals' => ['Referrals', 'referrals.php', $flagged],
        'levels' => ['Earner levels', 'levels.php', 0],
        'interests' => ['Interests', 'interests.php', 0],
        'announcements' => ['Announcements', 'announcements.php', 0],
    ];
    $html = '<nav class="card money-tabs" aria-label="Growth sections">';
    foreach ($tabs as $key => [$label, $url, $count]) {
        $html .= '<a href="' . h($url) . '"' . ($key === $active ? ' class="active" aria-current="page"' : '') . '>' . h($label)
            . ($count > 0 ? ' <span class="badge badge-warning">' . (int) $count . '</span>' : '') . '</a>';
    }
    return $html . '</nav>';
}

/** Admin page guard shared by the growth pages: returns [$pdo, $admin]. */
function yolotask_admin_growth_boot(string $logKey): array
{
    if (yolotask_role() !== 'admin') yolotask_error_page(404);
    yolotask_private_headers();
    try {
        $pdo = \YoloTask\Core\Database::connection();
        $admin = yolotask_require_user($pdo);
    } catch (Throwable $e) {
        \YoloTask\Core\Logger::error($logKey, ['exception' => get_class($e)]);
        yolotask_error_page(500);
    }
    return [$pdo, $admin];
}
