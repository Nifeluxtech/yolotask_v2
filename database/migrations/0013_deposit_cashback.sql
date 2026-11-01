-- YOLOTASK V2: advertiser deposit cashback.
--
-- Every confirmed AfrStar top-up (a collection callback that credits the
-- order, or an admin crediting a reviewed amount-mismatch payment) pays the
-- advertiser cashback = credited amount x cashback.rate_bps / 10000, rounded
-- down to the kobo, into the same wallet balance the deposit credited.
-- Ledger: Dr system:deposit_cashback / Cr advertiser wallet (available),
-- idempotency key cashback:<payment_order_id>. Admin wallet credits,
-- adjustments and bonuses are not payment orders and never earn cashback;
-- cashback is not a payment order either, so it never earns referral
-- commission or further cashback.
--
-- Numbering: follows 0012_leaderboard.sql. 0010/0011 stay reserved
-- placeholders that the runner skips without recording; nothing here depends
-- on them.

-- One row per confirmed deposit that was considered for cashback: 'paid'
-- (cashback credited), 'skipped' (cashback off, below the minimum, or the
-- account not active at confirmation; nothing posted) or 'reversed' (an
-- admin took a paid cashback back). The unique order id makes a redelivered
-- callback or a repeated catch-up a no-op.
CREATE TABLE deposit_cashbacks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    payment_order_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    base_kobo BIGINT NOT NULL,
    rate_bps INT UNSIGNED NOT NULL,
    min_deposit_kobo BIGINT NOT NULL DEFAULT 0,
    amount_kobo BIGINT NOT NULL DEFAULT 0,
    status ENUM('paid', 'skipped', 'reversed') NOT NULL,
    skip_reason VARCHAR(40) NULL,
    ledger_transaction_id BIGINT UNSIGNED NULL,
    reverse_transaction_id BIGINT UNSIGNED NULL,
    reversed_by_user_id BIGINT UNSIGNED NULL,
    reversed_at DATETIME NULL,
    reverse_reason VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_deposit_cashbacks_order (payment_order_id),
    KEY ix_deposit_cashbacks_user (user_id, id),
    KEY ix_deposit_cashbacks_status (status, id),
    CONSTRAINT fk_deposit_cashbacks_order FOREIGN KEY (payment_order_id) REFERENCES payment_orders(id),
    CONSTRAINT fk_deposit_cashbacks_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_deposit_cashbacks_tx FOREIGN KEY (ledger_transaction_id) REFERENCES ledger_transactions(id),
    CONSTRAINT fk_deposit_cashbacks_reverse_tx FOREIGN KEY (reverse_transaction_id) REFERENCES ledger_transactions(id),
    CONSTRAINT fk_deposit_cashbacks_reverser FOREIGN KEY (reversed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Expense account (debit-normal). A reversal credits it back.
INSERT IGNORE INTO ledger_accounts (code, name, kind, normal_side, allow_negative) VALUES
    ('system:deposit_cashback', 'Advertiser deposit cashback paid (expense)', 'system', 'debit', 0);

-- Defaults: off (0 bps) and no minimum. Range 0-5000 bps (0-50%).
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    ('cashback.rate_bps', '0'),
    ('cashback.min_deposit_kobo', '0');
