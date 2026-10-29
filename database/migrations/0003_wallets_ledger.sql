-- YOLOTASK V2 Phase 3: settings, wallets and the double-entry ledger, migration 0003.
-- Additive only. Money is stored as integer kobo (BIGINT); never floats.
-- Only YoloTask\Money\Ledger posts entries; every transaction balances
-- (total debits = total credits) and carries a unique idempotency key.

CREATE TABLE settings (
    setting_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    setting_value VARCHAR(255) NOT NULL,
    updated_by_user_id BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key),
    CONSTRAINT fk_settings_user FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (setting_key, setting_value) VALUES
    ('money.topup_min_kobo', '10000'),
    ('money.topup_max_kobo', '50000000'),
    ('money.withdraw_min_kobo', '100000'),
    ('money.withdraw_max_kobo', '50000000'),
    ('money.payin_expiry_hours', '24');

CREATE TABLE wallets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'NGN',
    available_kobo BIGINT NOT NULL DEFAULT 0,
    held_kobo BIGINT NOT NULL DEFAULT 0,
    status ENUM('active', 'frozen') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_wallets_user_currency (user_id, currency),
    CONSTRAINT fk_wallets_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every wallet has two ledger accounts (available, held). System accounts
-- have wallet_id NULL and a fixed code. normal_side says which side
-- increases the balance: user wallets are liabilities (credit-normal),
-- afrstar_clearing is money held at the provider (debit-normal).
CREATE TABLE ledger_accounts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name VARCHAR(120) NOT NULL,
    kind ENUM('system', 'wallet_available', 'wallet_held') NOT NULL,
    normal_side ENUM('debit', 'credit') NOT NULL,
    wallet_id BIGINT UNSIGNED NULL,
    currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'NGN',
    balance_kobo BIGINT NOT NULL DEFAULT 0,
    allow_negative TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ledger_accounts_code (code),
    KEY ix_ledger_accounts_wallet (wallet_id),
    CONSTRAINT fk_ledger_accounts_wallet FOREIGN KEY (wallet_id) REFERENCES wallets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO ledger_accounts (code, name, kind, normal_side, allow_negative) VALUES
    ('system:afrstar_clearing', 'AfrStar clearing (funds at provider)', 'system', 'debit', 1),
    ('system:adjustments', 'Manual adjustments (platform)', 'system', 'debit', 1);

CREATE TABLE ledger_transactions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    idempotency_key VARCHAR(120) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    type VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    description VARCHAR(255) NOT NULL,
    reference_type VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
    reference_id BIGINT UNSIGNED NULL,
    amount_kobo BIGINT UNSIGNED NOT NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ledger_transactions_key (idempotency_key),
    KEY ix_ledger_transactions_type_time (type, created_at),
    KEY ix_ledger_transactions_ref (reference_type, reference_id),
    CONSTRAINT fk_ledger_transactions_user FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ledger_entries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    transaction_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    direction ENUM('debit', 'credit') NOT NULL,
    amount_kobo BIGINT UNSIGNED NOT NULL,
    balance_after_kobo BIGINT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_ledger_entries_account (account_id, id),
    KEY ix_ledger_entries_transaction (transaction_id),
    CONSTRAINT fk_ledger_entries_transaction FOREIGN KEY (transaction_id) REFERENCES ledger_transactions(id),
    CONSTRAINT fk_ledger_entries_account FOREIGN KEY (account_id) REFERENCES ledger_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
