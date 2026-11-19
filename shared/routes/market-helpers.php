<?php
declare(strict_types=1);

/** View helpers for the marketplace pages (migration 0022): portals, main site and admin. */

use YoloTask\Marketplace\Pricing;

require_once __DIR__ . '/money-helpers.php';

// Every marketplace page links the dedicated .mk- stylesheet (shared/assets/css/market.css).
yolotask_page_css('assets/css/market.css');

/** Public cover image URL (served by the main site's cover.php). */
function yolotask_mkt_cover_url(int $productId): string
{
    return yolotask_portal_url('main') . '/cover.php?id=' . $productId;
}

/** Cover <img>, or a branded placeholder (tinted panel with the title's initial) when the product has no cover. */
function yolotask_mkt_cover(array $p, bool $hasCover, string $alt = '', bool $decorative = false): string
{
    if (!$hasCover) {
        $title = trim((string) ($p['title'] ?? ''));
        $initial = $title !== '' ? mb_strtoupper(mb_substr($title, 0, 1)) : '';
        return '<div class="mkt-cover mkt-cover-empty mk-cover mk-cover-empty mk-tone-' . ((int) ($p['id'] ?? 0) % 6) . '" aria-hidden="true">'
            . ($initial !== '' ? '<span class="mk-cover-initial">' . h($initial) . '</span>' : '')
            . '<span class="mk-cover-icon">' . yolotask_nav_icon('bag') . '</span></div>';
    }
    return '<img class="mkt-cover mk-cover" loading="lazy" decoding="async" src="' . h(yolotask_mkt_cover_url((int) $p['id'])) . '" alt="' . ($decorative ? '' : h($alt !== '' ? $alt : (string) $p['title'])) . '">';
}

/** Whole-number percent saved when a bonus price applies (0 when none). */
function yolotask_mkt_discount_percent(array $p): int
{
    $normal = (int) $p['price_kobo'];
    $list = Pricing::listPrice($normal, isset($p['bonus_price_kobo']) ? (int) $p['bonus_price_kobo'] : null);
    return $normal > 0 && $list < $normal ? (int) floor(($normal - $list) * 100 / $normal) : 0;
}

/**
 * Card / hero media frame: the cover in a fixed aspect-ratio frame with an
 * optional sale ribbon. $href makes the frame a (duplicate, unfocusable) link to
 * the product so the whole image is a tap target; the title link stays the
 * accessible one.
 */
function yolotask_mkt_media(array $p, bool $hasCover, string $href = '', string $extraClass = ''): string
{
    $off = yolotask_mkt_discount_percent($p);
    $inner = yolotask_mkt_cover($p, $hasCover, '', $href !== '');
    if ($off > 0) $inner .= '<span class="mk-ribbon">−' . $off . '%</span>';
    $class = 'mk-media' . ($extraClass !== '' ? ' ' . $extraClass : '');
    if ($href !== '') return '<a class="' . h($class) . '" href="' . h($href) . '" target="_blank" rel="noopener" tabindex="-1" aria-hidden="true">' . $inner . '</a>';
    return '<div class="' . h($class) . '">' . $inner . '</div>';
}

/**
 * Small descriptive chips derived from columns the product already has
 * (delivery type, resale listing, resell rights, affiliate commission).
 * @return list<array{0:string,1:string}> [label, tone]
 */
function yolotask_mkt_chips(array $p, bool $showAffiliate = false): array
{
    $chips = [[($p['delivery_type'] ?? 'link') === 'file' ? 'Download' : 'Instant access', 'info']];
    if (($p['parent_product_id'] ?? null) !== null) $chips[] = ['Resale', 'muted'];
    elseif (!empty($p['resell_allowed']) && ($p['resell_price_kobo'] ?? null) !== null) $chips[] = ['Resell rights', 'violet'];
    if ($showAffiliate && (int) ($p['affiliate_percent'] ?? 0) > 0) $chips[] = [(int) $p['affiliate_percent'] . '% commission', 'success'];
    return $chips;
}

function yolotask_mkt_chips_html(array $p, bool $showAffiliate = false): string
{
    $html = '<ul class="mk-chips" aria-label="Product details">';
    foreach (yolotask_mkt_chips($p, $showAffiliate) as [$label, $tone]) $html .= '<li class="mk-chip mk-chip-' . h($tone) . '">' . h($label) . '</li>';
    return $html . '</ul>';
}

/** Seller line: avatar initial, name and sales count. */
function yolotask_mkt_seller(array $p): string
{
    $name = (string) ($p['owner_name'] ?? '');
    $initial = $name !== '' ? mb_strtoupper(mb_substr($name, 0, 1)) : '?';
    $sold = (int) ($p['sales_count'] ?? 0);
    return '<p class="mk-seller"><span class="mk-avatar" aria-hidden="true">' . h($initial) . '</span><span class="mk-seller-name">By ' . h($name) . '</span>'
        . ($sold > 0 ? '<span class="mk-sold">' . number_format($sold) . ' sold</span>' : '') . '</p>';
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
    $html = '<div class="mkt-price mk-price"><span class="price-now">' . h(yolotask_money($list)) . '</span>';
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
    $html = '<nav class="card money-tabs mk-tabs" aria-label="Marketplace sections">';
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
