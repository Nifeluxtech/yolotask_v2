<?php
declare(strict_types=1);

/** View helpers for the marketplace pages (migration 0022): portals, main site and admin. */

use YoloTask\Marketplace\Pricing;

require_once __DIR__ . '/money-helpers.php';

/** Public cover image URL (served by the main site's cover.php). */
function yolotask_mkt_cover_url(int $productId): string
{
    return yolotask_portal_url('main') . '/cover.php?id=' . $productId;
}

/** Cover <img>, or a placeholder when the product has no cover. */
function yolotask_mkt_cover(array $p, bool $hasCover, string $alt = ''): string
{
    if (!$hasCover) return '<div class="mkt-cover mkt-cover-empty" aria-hidden="true">' . yolotask_nav_icon('bag') . '</div>';
    return '<img class="mkt-cover" loading="lazy" src="' . h(yolotask_mkt_cover_url((int) $p['id'])) . '" alt="' . h($alt !== '' ? $alt : (string) $p['title']) . '">';
}

/** Has this product (or, for a resale listing, its original) a cover? */
function yolotask_mkt_has_cover(array $p): bool
{
    return !empty($p['cover_path']) || !empty($p['source_product_id']);
}

/** Price with the normal price struck through when a bonus price applies. */
function yolotask_mkt_price(array $p): string
{
    $list = Pricing::listPrice((int) $p['price_kobo'], isset($p['bonus_price_kobo']) ? (int) $p['bonus_price_kobo'] : null);
    $html = '<div class="mkt-price"><span class="price-now">' . h(yolotask_money($list)) . '</span>';
    if ($list < (int) $p['price_kobo']) $html .= '<s class="price-old" aria-label="Normal price">' . h(yolotask_money($p['price_kobo'])) . '</s>';
    return $html . '</div>';
}

function yolotask_mkt_status_badge(string $status): string
{
    [$label, $class] = match ($status) {
        'live' => ['Live', 'badge-success'],
        'pending_review' => ['In review', 'badge-warning'],
        'paused' => ['Paused', 'badge-info'],
        'rejected' => ['Not approved', 'badge-danger'],
        default => [ucfirst(str_replace('_', ' ', $status)), 'badge-info'],
    };
    return '<span class="badge ' . $class . '">' . h($label) . '</span>';
}

/** Sub-navigation for the earner / advertiser marketplace pages. */
function yolotask_mkt_tabs(string $active): string
{
    $tabs = ['browse' => ['Browse', 'marketplace.php'], 'products' => ['My Products', 'my-products.php'], 'sales' => ['My Sales', 'my-sales.php']];
    $html = '<nav class="card money-tabs" aria-label="Marketplace sections">';
    foreach ($tabs as $key => [$label, $url]) {
        $html .= '<a href="' . h($url) . '"' . ($key === $active ? ' class="active" aria-current="page"' : '') . '>' . h($label) . '</a>';
    }
    return $html . '</nav>';
}

/** Read a POSTed string safely. */
function yolotask_mkt_post(string $key, int $max = 5000): string
{
    return is_string($_POST[$key] ?? null) ? mb_substr($_POST[$key], 0, $max) : '';
}

/** Render a delivery text: a lone https link becomes a button, anything else is shown as text. */
function yolotask_mkt_delivery_text(string $text): string
{
    $t = trim($text);
    if (preg_match('#^https?://\S+$#i', $t) && filter_var($t, FILTER_VALIDATE_URL)) {
        return '<p><a class="btn btn-primary" href="' . h($t) . '" rel="noopener noreferrer" target="_blank">Open your product</a></p><p class="text-sm text-faint mkt-delivery">' . h($t) . '</p>';
    }
    return '<div class="mkt-delivery">' . h($t) . '</div>';
}
