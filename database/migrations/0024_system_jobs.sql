-- YOLOTASK V2 migration 0024: YOLOTASK jobs ("system jobs") posted by the platform itself.
--
-- Like YOLOTASK tasks (migration 0019), a system job is owned by the closed
-- system account "YOLOTASK" (setting platform_tasks.owner_user_id) and runs
-- through the normal jobs flow (migration 0023): start, steps, submit, send
-- back, reject, appeal, expiry, auto-approve. Differences:
--   * jobs.is_system = 1; created by an admin (Admin -> Jobs -> New YOLOTASK
--     job) or scripts/create-betfugu-jobs.php. No wallet, no escrow: publishing
--     moves no money; funded / spent / refunded stay 0 for the whole life of
--     the job. slots is a cap (up to 10,000); closing refunds nothing.
--   * The earner gets the FULL pay (earner_percent 100, fee 0). Minimum pay ₦1
--     (jobs.min_pay_kobo does not apply).
--   * Approve (admin, reviewer, auto) and appeal overturn are paid by the
--     platform: Dr system:platform_job_payouts (expense, debit-normal) / Cr
--     earner available or system:reward_hold. Keys job-approve:<attempt> and
--     job-appeal-approve:<appeal>, ledger type job_reward as for every job.
--   * There is no advertiser to review: admins decide in Admin -> Jobs, and
--     reviewers work them in the reviewer portal's "YOLOTASK jobs" queue
--     (claims item_type job_attempt, decisions logged in reviewer_actions).
-- Invariants (Jobs\Jobs::reconcile): the per-job escrow check skips system
-- jobs; system jobs keep funded = spent = refunded = fee = 0; and
--   system:platform_job_payouts = sum of pay of approved attempts on system jobs.
--
-- Additive and safe with the previous release's code: one column with a
-- default the old code never sets, a CHECK that only binds is_system = 1
-- rows, new ENUM values the old code never writes, one ledger account.
-- Numbering: follows 0023_jobs.sql; depends on 0019 (system account) and 0023.

-- 1. The system-job flag, and money columns that must stay unused on system jobs.
ALTER TABLE jobs
    ADD COLUMN is_system TINYINT(1) NOT NULL DEFAULT 0 AFTER advertiser_user_id,
    ADD KEY ix_jobs_system (is_system, status, id);

ALTER TABLE jobs
    ADD CONSTRAINT chk_jobs_system CHECK (
        is_system = 0
        OR (funded_kobo = 0 AND spent_kobo = 0 AND refunded_kobo = 0 AND fee_kobo = 0 AND earner_percent = 100 AND earner_kobo = pay_kobo));

-- 2. Reviewers decide system-job submissions (new ENUM values only).
ALTER TABLE job_attempts
    MODIFY decision_source ENUM('advertiser', 'auto', 'admin', 'appeal', 'reviewer') NULL;

ALTER TABLE review_claims
    MODIFY item_type ENUM('appeal', 'submission', 'job_appeal', 'job_attempt') NOT NULL;

ALTER TABLE reviewer_actions
    MODIFY action ENUM('appeal_upheld', 'appeal_overturned', 'appeal_override_upheld', 'appeal_override_overturned', 'submission_approved', 'submission_rejected',
        'job_appeal_upheld', 'job_appeal_overturned', 'job_approved', 'job_sent_back', 'job_rejected') NOT NULL,
    MODIFY item_type ENUM('appeal', 'submission', 'job_appeal', 'job_attempt') NOT NULL;

-- 3. The system account that owns YOLOTASK tasks and jobs (normally created by 0019; repeated here, idempotent).
INSERT INTO users (display_name, email, password_hash, role, status, email_verified_at, closed_at)
    SELECT 'YOLOTASK', 'platform-tasks@system.yolotask.invalid', '!', 'advertiser', 'closed', UTC_TIMESTAMP(), UTC_TIMESTAMP()
    FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE email = 'platform-tasks@system.yolotask.invalid');

INSERT INTO settings (setting_key, setting_value)
    SELECT 'platform_tasks.owner_user_id', CAST(id AS CHAR) FROM users WHERE email = 'platform-tasks@system.yolotask.invalid'
    ON DUPLICATE KEY UPDATE setting_value = setting_value;

-- 4. Ledger account.
INSERT IGNORE INTO ledger_accounts (code, name, kind, normal_side, allow_negative) VALUES
    ('system:platform_job_payouts', 'YOLOTASK job pay paid by the platform (expense)', 'system', 'debit', 0);
