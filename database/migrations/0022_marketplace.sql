-- YOLOTASK V2 migration 0022: digital-product Marketplace (Selar-style).
--
-- Advertisers are sellers (My Products). Earners are affiliates: they share a
-- personal link to a live product (main site /p.php?id=<product>&ref=<code>,
-- code = users.referral_code) and earn the product's affiliate commission %
-- on each confirmed sale. A seller may allow resell rights: an earner or
-- advertiser buys them at the seller's resell price and lists the product as
-- their own listing (own price, optional own title/cover, same delivery
-- content); the reseller keeps the proceeds of their listing.
--
-- Buyers need no account: name + email (+ phone for AfrStar), pay on the
-- active pay-in gateway exactly like an account activation. Order references
-- start YTM (top-ups YTP, withdrawals YTW, activation YTA); the AfrStar hub
-- never forwards them.
--
-- Money per confirmed sale (one ledger transaction, key mkt-sale:<order_sn>):
--   Dr <gateway clearing>               amount the buyer paid
--   Cr system:marketplace_revenue       platform fee (marketplace.fee_bps of the charged price)
--   Cr system:marketplace_hold          affiliate commission + seller share
-- After marketplace.hold_hours (default 72) the hourly cron releases each
-- earnings row: Dr system:marketplace_hold / Cr the user's wallet available
-- (key mkt-release:<earning id>). Frozen sellers are not released.
--
-- Advertisers may withdraw marketplace earnings only (released marketplace
-- earnings minus their earlier withdrawals that were not rejected/failed),
-- never their campaign deposits. See docs/marketplace.md.
--
-- Additive only; the previous release never reads these tables.

-- 1. Products and resale listings.
CREATE TABLE mkt_products (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    owner_user_id BIGINT UNSIGNED NOT NULL,
    parent_product_id BIGINT UNSIGNED NULL,          -- resale listing: the product whose resell rights were bought
    source_product_id BIGINT UNSIGNED NULL,          -- resale listing: the original product that holds the delivery content
    title VARCHAR(120) NOT NULL,
    description TEXT NOT NULL,
    cover_path VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NULL,
    cover_mime VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL,
    price_kobo BIGINT UNSIGNED NOT NULL,             -- normal price
    bonus_price_kobo BIGINT UNSIGNED NULL,           -- optional promo price, lower than the normal price; charged when set
    fee_bearer ENUM('customer', 'seller') NOT NULL DEFAULT 'customer',
    affiliate_percent TINYINT UNSIGNED NOT NULL DEFAULT 0,   -- 0 = affiliates off, max 90
    resell_allowed TINYINT(1) NOT NULL DEFAULT 0,
    resell_price_kobo BIGINT UNSIGNED NULL,
    delivery_type ENUM('file', 'link') NOT NULL DEFAULT 'link',
    delivery_path VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NULL,
    delivery_name VARCHAR(160) NULL,
    delivery_bytes BIGINT UNSIGNED NULL,
    delivery_text TEXT NULL,
    status ENUM('pending_review', 'live', 'paused', 'rejected') NOT NULL DEFAULT 'pending_review',
    reject_reason VARCHAR(255) NULL,
    reviewed_by_user_id BIGINT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    sales_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_mkt_products_owner (owner_user_id, id),
    KEY ix_mkt_products_status (status, id),
    KEY ix_mkt_products_parent (parent_product_id),
    CONSTRAINT fk_mkt_products_owner FOREIGN KEY (owner_user_id) REFERENCES users(id),
    CONSTRAINT fk_mkt_products_parent FOREIGN KEY (parent_product_id) REFERENCES mkt_products(id),
    CONSTRAINT fk_mkt_products_source FOREIGN KEY (source_product_id) REFERENCES mkt_products(id),
    CONSTRAINT fk_mkt_products_reviewer FOREIGN KEY (reviewed_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_mkt_products_price CHECK (price_kobo >= 10000 AND (bonus_price_kobo IS NULL OR (bonus_price_kobo >= 10000 AND bonus_price_kobo < price_kobo))),
    CONSTRAINT chk_mkt_products_affiliate CHECK (affiliate_percent <= 90)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Seller coupons (percent in basis points, or a fixed kobo amount).
CREATE TABLE mkt_coupons (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    owner_user_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NULL,                 -- NULL = every product of the owner
    code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
    kind ENUM('percent', 'fixed') NOT NULL,
    value INT UNSIGNED NOT NULL,                     -- percent: bps (1-9000); fixed: kobo
    expires_at DATETIME NULL,                        -- UTC
    max_uses INT UNSIGNED NULL,
    uses INT UNSIGNED NOT NULL DEFAULT 0,            -- confirmed paid orders only
    status ENUM('active', 'disabled') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_mkt_coupons_owner_code (owner_user_id, code),
    KEY ix_mkt_coupons_product (product_id),
    CONSTRAINT fk_mkt_coupons_owner FOREIGN KEY (owner_user_id) REFERENCES users(id),
    CONSTRAINT fk_mkt_coupons_product FOREIGN KEY (product_id) REFERENCES mkt_products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Orders: product sales (kind product) and resell-rights purchases (kind resell_rights).
CREATE TABLE mkt_orders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_sn VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    access_token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    kind ENUM('product', 'resell_rights') NOT NULL DEFAULT 'product',
    product_id BIGINT UNSIGNED NOT NULL,
    seller_user_id BIGINT UNSIGNED NOT NULL,
    buyer_user_id BIGINT UNSIGNED NULL,              -- resell rights: the signed-in buyer
    buyer_name VARCHAR(80) NOT NULL,
    buyer_email VARCHAR(254) NOT NULL,
    affiliate_user_id BIGINT UNSIGNED NULL,
    coupon_id BIGINT UNSIGNED NULL,
    coupon_code VARCHAR(32) NULL,
    list_price_kobo BIGINT UNSIGNED NOT NULL,        -- normal or bonus price at checkout
    discount_kobo BIGINT UNSIGNED NOT NULL DEFAULT 0,
    base_kobo BIGINT UNSIGNED NOT NULL,              -- price charged for the product (list - coupon)
    fee_bps INT UNSIGNED NOT NULL,
    fee_bearer ENUM('customer', 'seller') NOT NULL,
    fee_kobo BIGINT UNSIGNED NOT NULL,
    commission_kobo BIGINT UNSIGNED NOT NULL DEFAULT 0,
    seller_kobo BIGINT UNSIGNED NOT NULL,
    amount_kobo BIGINT UNSIGNED NOT NULL,            -- what the buyer pays (base + fee when the customer bears it)
    amount_paid_kobo BIGINT UNSIGNED NULL,
    status ENUM('created', 'pending', 'paid', 'review', 'rejected', 'expired', 'failed') NOT NULL DEFAULT 'created',
    gateway ENUM('afrstar', 'korapay') NOT NULL DEFAULT 'afrstar',
    platform_osn VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    pay_url VARCHAR(500) NULL,
    provider_reference VARCHAR(120) NULL,
    provider_message VARCHAR(255) NULL,
    ledger_transaction_id BIGINT UNSIGNED NULL,
    verified_by_user_id BIGINT UNSIGNED NULL,
    paid_at DATETIME NULL,
    download_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_mkt_orders_sn (order_sn),
    UNIQUE KEY uq_mkt_orders_tx (ledger_transaction_id),
    KEY ix_mkt_orders_product (product_id, status),
    KEY ix_mkt_orders_seller (seller_user_id, status, id),
    KEY ix_mkt_orders_affiliate (affiliate_user_id, status, id),
    KEY ix_mkt_orders_status_time (status, created_at),
    KEY ix_mkt_orders_gateway_status (gateway, status),
    CONSTRAINT fk_mkt_orders_product FOREIGN KEY (product_id) REFERENCES mkt_products(id),
    CONSTRAINT fk_mkt_orders_seller FOREIGN KEY (seller_user_id) REFERENCES users(id),
    CONSTRAINT fk_mkt_orders_buyer FOREIGN KEY (buyer_user_id) REFERENCES users(id),
    CONSTRAINT fk_mkt_orders_affiliate FOREIGN KEY (affiliate_user_id) REFERENCES users(id),
    CONSTRAINT fk_mkt_orders_coupon FOREIGN KEY (coupon_id) REFERENCES mkt_coupons(id),
    CONSTRAINT fk_mkt_orders_ledger FOREIGN KEY (ledger_transaction_id) REFERENCES ledger_transactions(id),
    CONSTRAINT fk_mkt_orders_verifier FOREIGN KEY (verified_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Earnings waiting for (or past) the hold: one row per paid order and role.
CREATE TABLE mkt_earnings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    role ENUM('seller', 'affiliate') NOT NULL,
    amount_kobo BIGINT UNSIGNED NOT NULL,
    status ENUM('held', 'released') NOT NULL DEFAULT 'held',
    release_at DATETIME NOT NULL,                    -- UTC
    released_at DATETIME NULL,
    ledger_transaction_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_mkt_earnings_order_role (order_id, role),
    KEY ix_mkt_earnings_due (status, release_at),
    KEY ix_mkt_earnings_user (user_id, status),
    CONSTRAINT fk_mkt_earnings_order FOREIGN KEY (order_id) REFERENCES mkt_orders(id),
    CONSTRAINT fk_mkt_earnings_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_mkt_earnings_ledger FOREIGN KEY (ledger_transaction_id) REFERENCES ledger_transactions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Resell rights owned (one per product and user).
CREATE TABLE mkt_resell_rights (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    order_id BIGINT UNSIGNED NULL,
    listing_product_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_mkt_resell_rights (product_id, user_id),
    CONSTRAINT fk_mkt_resell_rights_product FOREIGN KEY (product_id) REFERENCES mkt_products(id),
    CONSTRAINT fk_mkt_resell_rights_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_mkt_resell_rights_order FOREIGN KEY (order_id) REFERENCES mkt_orders(id),
    CONSTRAINT fk_mkt_resell_rights_listing FOREIGN KEY (listing_product_id) REFERENCES mkt_products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Affiliate link clicks (one counter per product and affiliate; a browser counts once per 30 days).
CREATE TABLE mkt_affiliate_links (
    product_id BIGINT UNSIGNED NOT NULL,
    affiliate_user_id BIGINT UNSIGNED NOT NULL,
    clicks INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (product_id, affiliate_user_id),
    KEY ix_mkt_affiliate_links_user (affiliate_user_id),
    CONSTRAINT fk_mkt_affiliate_links_product FOREIGN KEY (product_id) REFERENCES mkt_products(id),
    CONSTRAINT fk_mkt_affiliate_links_user FOREIGN KEY (affiliate_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Frozen sellers: their products are hidden and their earnings are not released.
CREATE TABLE mkt_frozen_sellers (
    user_id BIGINT UNSIGNED NOT NULL,
    reason VARCHAR(255) NOT NULL,
    frozen_by_user_id BIGINT UNSIGNED NULL,
    frozen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_mkt_frozen_sellers_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_mkt_frozen_sellers_admin FOREIGN KEY (frozen_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Ledger accounts.
INSERT IGNORE INTO ledger_accounts (code, name, kind, normal_side, allow_negative) VALUES
    ('system:marketplace_revenue', 'Marketplace platform fees (revenue)', 'system', 'credit', 0),
    ('system:marketplace_hold', 'Marketplace earnings on hold (owed to sellers and affiliates)', 'system', 'credit', 0);

-- 9. Settings.
INSERT INTO settings (setting_key, setting_value) VALUES
    ('marketplace.fee_bps', '500'),
    ('marketplace.hold_hours', '72')
    ON DUPLICATE KEY UPDATE setting_value = setting_value;
