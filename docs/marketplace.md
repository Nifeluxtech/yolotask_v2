# Marketplace (migration 0022)

A Selar-style marketplace for digital products. Advertisers sell, earners promote
as affiliates, anyone can buy (no account), and sellers can sell resell rights.

## Who does what

| Role | Can |
|---|---|
| Advertiser (seller) | Create products (My Products), set price / bonus price, fee bearer, affiliate %, resell rights; coupons; pause / resume; withdraw marketplace earnings. Can also buy resell rights. |
| Earner (affiliate) | Marketplace tab: every live product with price and "You earn ₦X per sale"; one tap gives a personal link. My Sales: clicks, sales, commission. Can buy resell rights and run resale listings with their own coupons. |
| Buyer (public) | Product page `https://yolotask.com.ng/p.php?id=<product>`; name + email (+ phone for AfrStar), optional coupon, pays on the active pay-in gateway. Delivery on the thank-you page and by email. |
| Admin | Admin → Tasks → Marketplace: review queue, orders, sellers (freeze), settings. |

Earner and advertiser docks show **Marketplace** (`marketplace.php`) instead of
Sign out; Sign out stays in the header (also on phones). Reviewer and admin
docks are unchanged. Inside the portals: Browse (`marketplace.php`), My Products
(`my-products.php`, `product-edit.php`), My Sales (`my-sales.php`).

## Products

- Title (3–120), cover image (JPEG/PNG/WebP ≤ 3 MB, re-encoded like proof
  screenshots, stored in `runtime/marketplace/covers`, served by the main site's
  `cover.php`), description (10–5,000).
- **Normal price** (₦100 – ₦10,000,000) and optional **bonus price** (promo
  price, must be lower than normal, ≥ ₦100). With a bonus price the product page
  and marketplace show the normal price struck through and charge the bonus price.
- **Fee bearer**: `customer` (buyer pays price + fee) or `seller` (fee taken from
  the seller's share).
- **Affiliate commission %**: 0 = affiliates off, max 90.
- **Resell rights**: on/off + resell rights price (set by the seller).
- **Delivery**: a file (≤ 50 MB; PDF, ZIP, EPUB, Office, audio, video, images…,
  stored in `runtime/marketplace/files`, never under a document root, downloaded
  only through `download.php` with the order's token) or a private link / text,
  revealed only after payment. A file may also carry a note.
- New products, and any change to title / description / cover / delivery, go to
  **admin review** (`pending_review`) and are hidden until approved. Price, fee
  and affiliate changes apply at once. Owners pause / resume live products.

## Resell rights and resale listings

1. A signed-in earner or advertiser taps "Buy resell rights" on a product that
   allows it and pays the resell price (+ fee when the product's fee bearer is
   the customer) on the gateway. No affiliate on rights purchases.
2. On payment, `mkt_resell_rights` gets a row; the seller earns the rights price
   minus the fee they bear (after the hold).
3. The buyer creates **one listing** per right (`product-edit.php?resale=<id>`):
   own price, optional own bonus price, title, description and cover (empty =
   the original's), own fee bearer and affiliate %, and their own coupons. Same
   delivery content (`source_product_id`). A listing cannot sell resell rights.
4. The listing goes through admin review like a product. The reseller is its
   seller and keeps its sale proceeds (minus fee and any affiliate commission).
   The original seller earns nothing on resales (they were paid for the rights).

## Coupons

Created in My Products by the owner (seller, or reseller for their listings):
code (auto-generated 8 characters, or custom 4–32 of `A–Z 0–9 - _`, unique per
owner, case-insensitive), **percent** (1–90 %) or **fixed naira** off, one
product or all the owner's products, optional expiry (Lagos time) and maximum
uses; owners can turn a coupon off / on.

The buyer enters it on the product page ("Apply" shows the new total). It is
checked server-side again when paying (`Pricing::couponProblem`: owner matches
the product's owner, scope, active, not expired, uses left). The discount comes
off the charged price (bonus price if set) **before fees**; affiliate commission
is computed on the discounted price; the price never goes below ₦100. The coupon
is recorded on the order (`coupon_id`, `coupon_code`, `discount_kobo`) and its
`uses` counter grows **only when the payment is confirmed** (several buyers can
be at checkout with the last use; the extra confirmed ones are still honoured).

## Money

All rules are pure functions in `YoloTask\Marketplace\Pricing` (tested by
`tests/smoke/marketplace.php`). Amounts in kobo, rounding down.

```
list        = bonus price if set, else normal price
discount    = coupon on list (never below ₦100)
base        = list - discount                         (price actually charged)
fee         = base × marketplace.fee_bps / 10000       (default 500 = 5 %)
customer bears: buyer pays base + fee, seller pool = base
seller bears:   buyer pays base,       seller pool = base - fee
commission  = base × affiliate% / 100, capped at the pool (0 without a valid affiliate)
seller      = pool - commission
amount paid = fee + commission + seller
```

Example: normal ₦10,000, bonus ₦8,000, coupon 10 %, 30 % affiliate, 5 % fee paid
by the customer → base ₦7,200, fee ₦360, buyer pays ₦7,560, affiliate ₦2,160,
seller ₦5,040.

**Self-referral** earns nothing: the affiliate is the seller, the signed-in buyer
(resell rights), or has the buyer's email. Only active earners who may work
(account activation rules) can be affiliates.

### Ledger

New system accounts: `system:marketplace_revenue` (credit, platform fees) and
`system:marketplace_hold` (credit, earnings owed to sellers and affiliates).

- Confirmed payment, key `mkt-sale:<order_sn>`, type `marketplace_sale`:
  Dr gateway clearing (`system:afrstar_clearing` / `system:korapay_clearing`)
  amount paid / Cr `system:marketplace_revenue` fee (+ any accepted overpayment)
  / Cr `system:marketplace_hold` commission + seller share. One `mkt_earnings`
  row per payee (`seller`, `affiliate`) with `release_at` = now +
  `marketplace.hold_hours` (default 72).
- Release, key `mkt-release:<earning id>`, type `marketplace_release`: Dr
  `system:marketplace_hold` / Cr the payee's wallet available. Run by the hourly
  cron (`Marketplace::releaseDue`), and immediately after a sale when the hold is
  0 hours. Earnings on orders of a **frozen seller** are not released (their
  products are also hidden) until an admin unfreezes them.
- `Ledger::reconcile` includes `Marketplace::reconcile`: revenue = fees of paid
  orders; hold = held earnings; every paid order and released earning matches its
  posting; earnings per order = commission + seller share.

### Advertiser withdrawals (design)

The advertiser wallet already holds campaign deposits, which must never be
withdrawable. Rather than adding a second wallet per user (which would touch
every wallet query, the `uq_wallets_user_currency` key and the withdrawal hold
postings), earnings are credited to the same wallet and the **withdrawable
amount is computed**:

```
withdrawable = min(wallet available,
                   released marketplace earnings
                   - advertiser withdrawals not rejected/failed)
```

Rejected and failed withdrawals are refunded to the wallet, so they do not count.
Everything else is the existing earner flow: bank account (now also on the
advertiser portal: `bank-account.php`), `withdraw.php` preview / confirm,
cooldown, velocity limits, admin review, AfrStar / Korapay payout, callbacks.
Advertisers pay the base withdrawal fee (levels are an earner feature) and need
no account activation. Implemented in `Payments\Withdrawals::withdrawable /
rateFor / quoteFor`. Money is fungible inside the wallet: if an advertiser spends
sales income on campaigns, their withdrawable amount shrinks with the available
balance; they can never withdraw more than they earned.

## Payments

Orders (`mkt_orders`) use reference prefix **YTM** (top-ups YTP, withdrawals
YTW, activation YTA; the AfrStar callback hub never forwards any of them) and
are paid exactly like account activation: `Gateway::startPayin` on the active
pay-in gateway, return URL `https://yolotask.com.ng/order.php?o=<sn>&t=<token>`.

- AfrStar: the collection callback routes YTM orders to
  `CallbackProcessor::marketplace` (signature, OSN, status checks) →
  `Marketplace\Orders::settle`.
- Korapay: webhook, return page and the five-minute sweep (`mkt_orders` added to
  `KorapayProcessor::sweep`) verify with Korapay → `Orders::settle`.
- Exact amount → paid (above). Any other amount → `review` with nothing posted;
  admin **accepts** it when at least the order amount arrived (excess = platform
  revenue) or **rejects** it (refund outside the app).
- Admin "Confirm as paid" for waiting / expired / failed orders found in the
  gateway portal (AfrStar has no status API); same ledger key as the callback.
- Waiting orders expire after `money.payin_expiry_hours` (five-minute cron).
- After payment: the buyer gets an email (Mailer, counts toward the mail budget)
  with the order link; seller and affiliate get inbox notifications in the new
  `marketplace` notification category; release notifications when earnings
  reach the wallet.

## Affiliate links

`https://yolotask.com.ng/p.php?id=<product>&ref=<users.referral_code>`. A valid
code (active earner allowed to work, not the product's owner, product pays
affiliates) is stored in the cookie `yt_mref_<product>` for 30 days (HttpOnly,
SameSite=Lax) and in the session. A browser counts one click per product until
the cookie changes. The order records `affiliate_user_id`.

## Admin

Admin → Tasks → **Marketplace** (`apps/admin/public/marketplace.php`):
- **Products**: review queue (default) with cover, prices, fee bearer, affiliate %,
  resell price, description and delivery (file download for review); approve or
  reject with a reason (seller notified). Filters by status and search.
- **Orders**: all orders with the full split; payment review (accept / reject),
  "Confirm as paid".
- **Sellers**: freeze by email with a reason / unfreeze.
- **Settings**: platform fee % (`marketplace.fee_bps`, 0–20 %) and hold hours
  (`marketplace.hold_hours`, 0–720). New values apply to new orders only.

## Rules respected

Maintenance mode gates every marketplace page on main, earner and advertiser
(bootstrap gate; callbacks and cron keep running). Activation: while
`activation.required` is on, an earner who has not activated cannot get affiliate
links, buy resell rights or be credited as an affiliate. CSRF on every form;
output escaped with `h()`.

## Tables

`mkt_products`, `mkt_coupons`, `mkt_orders`, `mkt_earnings`, `mkt_resell_rights`,
`mkt_affiliate_links`, `mkt_frozen_sellers`. Settings rows `marketplace.fee_bps`
(500) and `marketplace.hold_hours` (72).

## Not included (yet)

- Refunds / chargebacks of paid orders are handled outside the app (no reversal
  posting for already released earnings).
- No download limit or link expiry on a paid order's access link.
- Product files are limited by the host's `upload_max_filesize` /
  `post_max_size`; larger products should use a private link.
