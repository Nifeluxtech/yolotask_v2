-- YOLOTASK V2: AfrStar hub order registrations, migration 0018.
-- Additive only. Other sites that share the AfrStar merchant register each
-- order (order_sn + their callback URL) through hooks/afrstar/register.php,
-- signed with their own secret from config-private/afrstar-forward.php.
-- When AfrStar calls YOLOTASK about that order_sn, the hub relays the
-- callback to the registered URL. Never used for YOLOTASK's own YTP/YTW refs.

CREATE TABLE afrstar_forward_registrations (
    order_sn VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    client VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    callback_url VARCHAR(500) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (order_sn),
    KEY ix_afrstar_registrations_client (client, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
