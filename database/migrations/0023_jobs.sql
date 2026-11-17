-- YOLOTASK V2 migration 0023: Jobs (multi-step tasks that take longer).
--
-- An advertiser writes a job (name, overall instructions, optional link,
-- online or offline with a location, 2..jobs.max_steps steps, each with its own
-- required proof kinds), sets the pay per earner, the number of slots, the
-- time limit to finish once started and the review window. There are no
-- preset job types and no admin approval: publishing locks pay x slots from the
-- advertiser's wallet (available balance, like campaign funding) into the
-- single system account system:jobs_escrow and the job is live at once.
--
-- An earner starts a job (takes a slot, starts the timer), submits proof step
-- by step (step N unlocks step N+1, earlier steps stay editable), then submits
-- the whole job for review. The advertiser approves (earner paid), sends one
-- step back with a note (max jobs.send_back_max_rounds, fresh fix deadline of
-- jobs.send_back_fix_hours), or rejects with a reason (slot back to the job,
-- earner may appeal to the reviewer queue). No decision within the review
-- window -> auto-approve (cron). Timer or fix deadline missed -> attempt
-- expired, slot back, nothing paid.
--
-- Money (integer kobo, every posting idempotent):
--   publish / add slots   Dr advertiser wallet available / Cr system:jobs_escrow   job-fund:<job>:<seq>
--   approve               Dr system:jobs_escrow (pay) / Cr earner available or system:reward_hold
--                         (earner share, jobs.earner_percent rounded down to the kobo) / Cr system:jobs_revenue (rest)
--                                                                                   job-approve:<attempt> | job-appeal-approve:<appeal>
--   held reward release   Dr system:reward_hold / Cr earner available               job-release:<attempt>
--   close / remove / end  Dr system:jobs_escrow / Cr advertiser wallet available    job-refund:<job>:<seq>
--   overturn, no free slot Dr advertiser wallet (what it has) + Dr system:jobs_appeal_payouts (the rest)
--                         / Cr system:jobs_escrow                                    job-appeal-fund:<appeal>
-- Invariants (Jobs\Jobs::reconcile, part of Money\Ledger::reconcile):
--   per job   funded - spent - refunded = pay x (slots - approved_count)
--   total     system:jobs_escrow = sum of every job's funded - spent - refunded
--   revenue   system:jobs_revenue = fees of approved attempts
--   platform  system:jobs_appeal_payouts = platform_kobo of overturned job appeals
--   hold      system:reward_hold = held task rewards + held job rewards
--
-- Additive only: new tables, new ledger accounts and settings, and ENUM values
-- / one nullable column added to the shared reviewer tables (review_claims,
-- reviewer_actions). The previous release never reads the new tables and
-- never writes the new ENUM values. See docs/jobs.md.

-- 1. Jobs.
CREATE TABLE jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    advertiser_user_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(120) NOT NULL,
    instructions TEXT NOT NULL,
    link_url VARCHAR(500) NULL,
    mode ENUM('online', 'offline') NOT NULL DEFAULT 'online',
    location VARCHAR(160) NULL,                        -- offline jobs: state / city / address area, shown to earners
    step_count TINYINT UNSIGNED NOT NULL,
    pay_kobo BIGINT UNSIGNED NOT NULL,                 -- what the advertiser pays per approved earner
    earner_percent TINYINT UNSIGNED NOT NULL,          -- jobs.earner_percent when published
    earner_kobo BIGINT UNSIGNED NOT NULL,              -- floor(pay x earner_percent / 100)
    fee_kobo BIGINT UNSIGNED NOT NULL,                 -- pay - earner_kobo (platform revenue)
    slots INT UNSIGNED NOT NULL,                       -- slots currently funded (added slots in, refunded slots out)
    time_limit_hours SMALLINT UNSIGNED NOT NULL,       -- to finish once started
    review_hours SMALLINT UNSIGNED NOT NULL,           -- auto-approve after this long without a decision
    status ENUM('live', 'paused', 'closed', 'completed', 'removed') NOT NULL DEFAULT 'live',
    admin_paused TINYINT(1) NOT NULL DEFAULT 0,        -- paused by an admin: the advertiser cannot resume it
    status_reason VARCHAR(255) NULL,
    funded_kobo BIGINT UNSIGNED NOT NULL DEFAULT 0,
    spent_kobo BIGINT UNSIGNED NOT NULL DEFAULT 0,
    refunded_kobo BIGINT UNSIGNED NOT NULL DEFAULT 0,
    fund_seq INT UNSIGNED NOT NULL DEFAULT 0,
    refund_seq INT UNSIGNED NOT NULL DEFAULT 0,
    active_count INT UNSIGNED NOT NULL DEFAULT 0,      -- attempts in_progress, submitted or sent_back
    approved_count INT UNSIGNED NOT NULL DEFAULT 0,
    rejected_count INT UNSIGNED NOT NULL DEFAULT 0,
    expired_count INT UNSIGNED NOT NULL DEFAULT 0,
    published_at DATETIME NULL,
    closed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_jobs_advertiser (advertiser_user_id, status, id),
    KEY ix_jobs_status (status, id),
    CONSTRAINT fk_jobs_advertiser FOREIGN KEY (advertiser_user_id) REFERENCES users(id),
    CONSTRAINT chk_jobs_split CHECK (earner_kobo + fee_kobo = pay_kobo),
    CONSTRAINT chk_jobs_steps CHECK (step_count >= 2)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Steps (fixed once published).
CREATE TABLE job_steps (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_id BIGINT UNSIGNED NOT NULL,
    position TINYINT UNSIGNED NOT NULL,                -- 1..step_count
    title VARCHAR(120) NOT NULL,
    instructions TEXT NOT NULL,
    proof_kinds VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, -- comma list of image,text,link,file (at least one; all required)
    PRIMARY KEY (id),
    UNIQUE KEY uq_job_steps_position (job_id, position),
    CONSTRAINT fk_job_steps_job FOREIGN KEY (job_id) REFERENCES jobs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Attempts: one per earner and job (an earner can do a given job only once).
CREATE TABLE job_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_id BIGINT UNSIGNED NOT NULL,
    earner_user_id BIGINT UNSIGNED NOT NULL,
    advertiser_user_id BIGINT UNSIGNED NOT NULL,
    status ENUM('in_progress', 'submitted', 'sent_back', 'approved', 'rejected', 'expired', 'abandoned', 'cancelled') NOT NULL DEFAULT 'in_progress',
    pay_kobo BIGINT UNSIGNED NOT NULL,
    earner_kobo BIGINT UNSIGNED NOT NULL,
    fee_kobo BIGINT UNSIGNED NOT NULL,
    started_at DATETIME NOT NULL,
    deadline_at DATETIME NOT NULL,                     -- UTC: final submit due (time limit)
    submitted_at DATETIME NULL,                        -- last (re)submission for review
    first_submitted_at DATETIME NULL,
    review_due_at DATETIME NULL,                       -- UTC: auto-approve time while submitted
    send_back_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    sent_back_step_id BIGINT UNSIGNED NULL,
    send_back_note VARCHAR(500) NULL,
    fix_deadline_at DATETIME NULL,                     -- UTC: resubmit due while sent_back
    decision_source ENUM('advertiser', 'auto', 'admin', 'appeal') NULL,
    decided_by_user_id BIGINT UNSIGNED NULL,
    decided_at DATETIME NULL,
    reject_reason VARCHAR(255) NULL,
    ended_reason VARCHAR(255) NULL,                    -- expired / abandoned / cancelled
    reward_status ENUM('none', 'held', 'released') NOT NULL DEFAULT 'none',
    reward_release_at DATETIME NULL,
    reward_released_at DATETIME NULL,
    approve_transaction_id BIGINT UNSIGNED NULL,
    release_transaction_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_job_attempts_job_earner (job_id, earner_user_id),
    KEY ix_job_attempts_earner (earner_user_id, status, id),
    KEY ix_job_attempts_job_status (job_id, status, id),
    KEY ix_job_attempts_advertiser (advertiser_user_id, status, id),
    KEY ix_job_attempts_deadline (status, deadline_at),
    KEY ix_job_attempts_review_due (status, review_due_at),
    KEY ix_job_attempts_fix_due (status, fix_deadline_at),
    KEY ix_job_attempts_release (reward_status, reward_release_at),
    CONSTRAINT fk_job_attempts_job FOREIGN KEY (job_id) REFERENCES jobs(id),
    CONSTRAINT fk_job_attempts_earner FOREIGN KEY (earner_user_id) REFERENCES users(id),
    CONSTRAINT fk_job_attempts_advertiser FOREIGN KEY (advertiser_user_id) REFERENCES users(id),
    CONSTRAINT fk_job_attempts_step FOREIGN KEY (sent_back_step_id) REFERENCES job_steps(id),
    CONSTRAINT fk_job_attempts_decider FOREIGN KEY (decided_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_job_attempts_approve_tx FOREIGN KEY (approve_transaction_id) REFERENCES ledger_transactions(id),
    CONSTRAINT fk_job_attempts_release_tx FOREIGN KEY (release_transaction_id) REFERENCES ledger_transactions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Proof files (screenshots / images and documents), private runtime storage runtime/job-proofs.
CREATE TABLE job_files (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    attempt_id BIGINT UNSIGNED NOT NULL,
    step_id BIGINT UNSIGNED NOT NULL,
    kind ENUM('image', 'file') NOT NULL,
    storage_path VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    mime VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    original_name VARCHAR(160) NULL,
    bytes INT UNSIGNED NOT NULL,
    upload_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_job_files_attempt (attempt_id, step_id),
    KEY ix_job_files_hash (upload_sha256),
    CONSTRAINT fk_job_files_attempt FOREIGN KEY (attempt_id) REFERENCES job_attempts(id),
    CONSTRAINT fk_job_files_step FOREIGN KEY (step_id) REFERENCES job_steps(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. The current proof of each step of an attempt (replaced when the earner edits the step).
CREATE TABLE job_step_proofs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    attempt_id BIGINT UNSIGNED NOT NULL,
    step_id BIGINT UNSIGNED NOT NULL,
    position TINYINT UNSIGNED NOT NULL,
    text_answer TEXT NULL,
    link_url VARCHAR(500) NULL,
    image_file_id BIGINT UNSIGNED NULL,
    file_file_id BIGINT UNSIGNED NULL,
    edits SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_job_step_proofs (attempt_id, step_id),
    CONSTRAINT fk_job_step_proofs_attempt FOREIGN KEY (attempt_id) REFERENCES job_attempts(id),
    CONSTRAINT fk_job_step_proofs_step FOREIGN KEY (step_id) REFERENCES job_steps(id),
    CONSTRAINT fk_job_step_proofs_image FOREIGN KEY (image_file_id) REFERENCES job_files(id),
    CONSTRAINT fk_job_step_proofs_file FOREIGN KEY (file_file_id) REFERENCES job_files(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Send-back history (one row per round).
CREATE TABLE job_send_backs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    attempt_id BIGINT UNSIGNED NOT NULL,
    step_id BIGINT UNSIGNED NOT NULL,
    round TINYINT UNSIGNED NOT NULL,
    note VARCHAR(500) NOT NULL,
    sent_by_user_id BIGINT UNSIGNED NULL,
    fix_deadline_at DATETIME NOT NULL,
    fixed_at DATETIME NULL,                            -- the earner changed the step after it was sent back
    resubmitted_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_job_send_backs_round (attempt_id, round),
    CONSTRAINT fk_job_send_backs_attempt FOREIGN KEY (attempt_id) REFERENCES job_attempts(id),
    CONSTRAINT fk_job_send_backs_step FOREIGN KEY (step_id) REFERENCES job_steps(id),
    CONSTRAINT fk_job_send_backs_user FOREIGN KEY (sent_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Escrow movements per job (lock on publish / added slots, refunds, appeal top-ups).
CREATE TABLE job_funding_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_id BIGINT UNSIGNED NOT NULL,
    event ENUM('lock', 'refund', 'appeal_topup') NOT NULL,
    slots INT NOT NULL DEFAULT 0,
    amount_kobo BIGINT UNSIGNED NOT NULL,
    ledger_transaction_id BIGINT UNSIGNED NOT NULL,
    note VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_job_funding_events_job (job_id, id),
    CONSTRAINT fk_job_funding_events_job FOREIGN KEY (job_id) REFERENCES jobs(id),
    CONSTRAINT fk_job_funding_events_tx FOREIGN KEY (ledger_transaction_id) REFERENCES ledger_transactions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Appeals against a rejected attempt (reviewer queue "Job appeals"; one per attempt).
CREATE TABLE job_appeals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    attempt_id BIGINT UNSIGNED NOT NULL,
    job_id BIGINT UNSIGNED NOT NULL,
    earner_user_id BIGINT UNSIGNED NOT NULL,
    advertiser_user_id BIGINT UNSIGNED NOT NULL,
    status ENUM('open', 'upheld', 'overturned') NOT NULL DEFAULT 'open',
    message TEXT NOT NULL,
    rejection_reason VARCHAR(255) NULL,
    rejected_by_user_id BIGINT UNSIGNED NULL,
    rejected_at DATETIME NULL,
    resolution_note VARCHAR(500) NULL,
    resolved_by_user_id BIGINT UNSIGNED NULL,
    decided_by_role ENUM('reviewer', 'admin') NULL,
    resolved_at DATETIME NULL,
    escrow_kobo BIGINT UNSIGNED NOT NULL DEFAULT 0,    -- overturn paid from a free slot of the job
    wallet_kobo BIGINT UNSIGNED NOT NULL DEFAULT 0,    -- overturn topped up from the advertiser wallet
    platform_kobo BIGINT UNSIGNED NOT NULL DEFAULT 0,  -- overturn topped up by YOLOTASK (system:jobs_appeal_payouts)
    funding_transaction_id BIGINT UNSIGNED NULL,
    approve_transaction_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_job_appeals_attempt (attempt_id),
    KEY ix_job_appeals_queue (status, created_at, id),
    KEY ix_job_appeals_job (job_id, id),
    CONSTRAINT fk_job_appeals_attempt FOREIGN KEY (attempt_id) REFERENCES job_attempts(id),
    CONSTRAINT fk_job_appeals_job FOREIGN KEY (job_id) REFERENCES jobs(id),
    CONSTRAINT fk_job_appeals_earner FOREIGN KEY (earner_user_id) REFERENCES users(id),
    CONSTRAINT fk_job_appeals_advertiser FOREIGN KEY (advertiser_user_id) REFERENCES users(id),
    CONSTRAINT fk_job_appeals_rejecter FOREIGN KEY (rejected_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_job_appeals_resolver FOREIGN KEY (resolved_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_job_appeals_fund_tx FOREIGN KEY (funding_transaction_id) REFERENCES ledger_transactions(id),
    CONSTRAINT fk_job_appeals_approve_tx FOREIGN KEY (approve_transaction_id) REFERENCES ledger_transactions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Reviewer queue tables learn about job appeals (new ENUM values only; one nullable column).
ALTER TABLE review_claims
    MODIFY item_type ENUM('appeal', 'submission', 'job_appeal') NOT NULL;

ALTER TABLE reviewer_actions
    MODIFY action ENUM('appeal_upheld', 'appeal_overturned', 'appeal_override_upheld', 'appeal_override_overturned', 'submission_approved', 'submission_rejected', 'job_appeal_upheld', 'job_appeal_overturned') NOT NULL,
    MODIFY item_type ENUM('appeal', 'submission', 'job_appeal') NOT NULL,
    MODIFY submission_id BIGINT UNSIGNED NULL,
    ADD COLUMN job_attempt_id BIGINT UNSIGNED NULL AFTER submission_id,
    ADD CONSTRAINT fk_reviewer_actions_job_attempt FOREIGN KEY (job_attempt_id) REFERENCES job_attempts(id);

-- 10. Ledger accounts.
INSERT IGNORE INTO ledger_accounts (code, name, kind, normal_side, allow_negative) VALUES
    ('system:jobs_escrow', 'Jobs escrow (locked advertiser job budgets)', 'system', 'credit', 0),
    ('system:jobs_revenue', 'Jobs platform share (revenue)', 'system', 'credit', 0),
    ('system:jobs_appeal_payouts', 'Job appeal payouts covered by the platform', 'system', 'debit', 0);

-- 11. Settings.
INSERT INTO settings (setting_key, setting_value) VALUES
    ('jobs.enabled', '1'),
    ('jobs.min_pay_kobo', '20000'),
    ('jobs.earner_percent', '90'),
    ('jobs.max_steps', '20'),
    ('jobs.time_limit_min_hours', '1'),
    ('jobs.time_limit_max_hours', '336'),
    ('jobs.send_back_max_rounds', '2'),
    ('jobs.send_back_fix_hours', '48'),
    ('jobs.max_active_per_earner', '1')
    ON DUPLICATE KEY UPDATE setting_value = setting_value;
