-- YOLOTASK V2: AfrStar callback hub log, migration 0007 (afrstar_callback_forwards).
-- Additive only; safe to run once after 0006. (0007_growth_referrals_* is
-- still a placeholder and is skipped by scripts/migrate.php.)
-- One row per callback relayed to another app that shares the AfrStar
-- merchant (SPLUG today). Kept apart from payment_callbacks on purpose:
-- callback retries, stuck-payout checks and reconciliation never see these
-- rows. The body itself is not stored, only its size and SHA-256.

CREATE TABLE afrstar_callback_forwards (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    kind ENUM('collection', 'payout') NOT NULL,
    route VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_sn VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    remote_ip VARCHAR(45) NULL,
    content_type VARCHAR(120) NULL,
    body_bytes INT UNSIGNED NOT NULL DEFAULT 0,
    body_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    downstream_status SMALLINT UNSIGNED NULL,
    latency_ms INT UNSIGNED NOT NULL DEFAULT 0,
    outcome ENUM('delivered', 'rejected', 'failed', 'timeout') NOT NULL,
    error_code VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL,
    response_excerpt VARCHAR(60) NULL,
    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_afrstar_forwards_order (route, order_sn),
    KEY ix_afrstar_forwards_time (received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
