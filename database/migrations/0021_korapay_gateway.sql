-- YOLOTASK V2 migration 0021: Korapay as a second payment gateway.
-- Admins choose the gateway for new pay-ins (top-ups, activation fees) and
-- for new payouts (withdrawals) separately in Money settings
-- (payments.gateway_payin / payments.gateway_payout: 'afrstar' or 'korapay').
-- Every order, activation payment, withdrawal and callback row records the
-- gateway it used, so switching never strands money already in flight.
-- Money held at Korapay has its own clearing account, system:korapay_clearing.
-- Additive only; existing rows are AfrStar.

ALTER TABLE payment_orders
    ADD COLUMN gateway ENUM('afrstar', 'korapay') NOT NULL DEFAULT 'afrstar' AFTER status,
    ADD KEY ix_payment_orders_gateway_status (gateway, status);

ALTER TABLE activation_payments
    ADD COLUMN gateway ENUM('afrstar', 'korapay') NOT NULL DEFAULT 'afrstar' AFTER status,
    ADD KEY ix_activation_payments_gateway_status (gateway, status);

ALTER TABLE withdrawals
    ADD COLUMN gateway ENUM('afrstar', 'korapay') NOT NULL DEFAULT 'afrstar' AFTER status,
    ADD KEY ix_withdrawals_gateway_status (gateway, status);

ALTER TABLE payment_callbacks
    ADD COLUMN gateway ENUM('afrstar', 'korapay') NOT NULL DEFAULT 'afrstar' AFTER kind,
    ADD COLUMN signature VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER raw_body;

INSERT INTO ledger_accounts (code, name, kind, normal_side, allow_negative) VALUES
    ('system:korapay_clearing', 'Korapay clearing (funds at provider)', 'system', 'debit', 1)
    ON DUPLICATE KEY UPDATE name = name;

INSERT INTO settings (setting_key, setting_value) VALUES
    ('payments.gateway_payin', 'afrstar'),
    ('payments.gateway_payout', 'afrstar')
    ON DUPLICATE KEY UPDATE setting_value = setting_value;
