<?php
declare(strict_types=1);

/** View helpers shared by the wallet, top-up, withdrawal and admin money pages. */

use YoloTask\Money\Money;

function yolotask_money(int|string|null $kobo): string
{
    return Money::format((int) ($kobo ?? 0));
}

/** [label, badge class] for payment order and withdrawal statuses. */
function yolotask_money_status(string $status): array
{
    return match ($status) {
        'paid' => ['Paid', 'badge-success'],
        'credited' => ['Credited', 'badge-success'],
        'pending', 'created' => ['Waiting for AfrStar', 'badge-warning'],
        'pending_review' => ['Waiting for review', 'badge-warning'],
        'processing' => ['Sending to bank', 'badge-info'],
        'review' => ['Being checked', 'badge-warning'],
        'open' => ['Open', 'badge-warning'],
        'rejected' => ['Rejected', 'badge-danger'],
        'dismissed' => ['Dismissed', 'badge-info'],
        'failed' => ['Failed', 'badge-danger'],
        'expired' => ['Expired', 'badge-info'],
        default => [ucfirst(str_replace('_', ' ', $status)), 'badge-info'],
    };
}

function yolotask_money_badge(string $status): string
{
    [$label, $class] = yolotask_money_status($status);
    return '<span class="badge ' . $class . '">' . h($label) . '</span>';
}

/** Notice card shown when AfrStar credentials are not configured. */
function yolotask_payments_unavailable_card(string $what): string
{
    return '<section class="card notice-card notice-warning" role="status"><span class="verify-icon">' . yolotask_nav_icon('bell') . '</span>'
        . '<div><strong>Payments are not available yet</strong><p class="text-sm text-faint">' . h($what) . ' will open here as soon as our payment partner is connected. Your balance is safe.</p></div></section>';
}

/** Parse a naira amount typed by a user; null when invalid. */
function yolotask_parse_naira(string $input): ?int
{
    try {
        return Money::toKobo($input);
    } catch (InvalidArgumentException) {
        return null;
    }
}

/** Admin money sub-navigation. */
function yolotask_admin_money_tabs(PDO $pdo, string $active): string
{
    $wd = 0;
    $rev = 0;
    try {
        $wd = (int) $pdo->query("SELECT COUNT(*) FROM withdrawals WHERE status = 'pending_review'")->fetchColumn();
        $rev = \YoloTask\Payments\PaymentReview::openCount($pdo);
    } catch (Throwable) {
    }
    $tabs = [
        'wallets' => ['Wallets', 'wallets.php', 0],
        'withdrawals' => ['Withdrawals', 'withdrawals.php', $wd],
        'payments' => ['Payments & review', 'payments.php', $rev],
        'transactions' => ['Ledger', 'transactions.php', 0],
        'settings' => ['Money settings', 'payment-settings.php', 0],
    ];
    $html = '<nav class="card money-tabs" aria-label="Money sections">';
    foreach ($tabs as $key => [$label, $url, $count]) {
        $html .= '<a href="' . h($url) . '"' . ($key === $active ? ' class="active" aria-current="page"' : '') . '>' . h($label)
            . ($count > 0 ? ' <span class="badge badge-warning">' . (int) $count . '</span>' : '') . '</a>';
    }
    return $html . '</nav>';
}

/** Common admin POST guard: CSRF or bounce back with an error. */
function yolotask_admin_csrf_or_back(string $back): void
{
    if (!\YoloTask\Security\Csrf::verify(is_string($_POST['_csrf'] ?? null) ? $_POST['_csrf'] : null)) {
        yolotask_flash('Your form expired. Please try again.', 'error');
        yolotask_redirect($back);
    }
}

function yolotask_pager(array $result, callable $url): string
{
    if ($result['pages'] <= 1) return '';
    $html = '<nav class="flex-between pager" aria-label="Pages">';
    $html .= $result['page'] > 1 ? '<a class="btn btn-ghost btn-sm" href="' . h($url($result['page'] - 1)) . '">Previous</a>' : '<span></span>';
    $html .= '<span class="text-xs text-faint">Page ' . (int) $result['page'] . ' of ' . (int) $result['pages'] . '</span>';
    $html .= $result['page'] < $result['pages'] ? '<a class="btn btn-ghost btn-sm" href="' . h($url($result['page'] + 1)) . '">Next</a>' : '<span></span>';
    return $html . '</nav>';
}
