-- Account names can now be confirmed through Korapay's bank resolve API.
ALTER TABLE bank_accounts
    MODIFY name_verified_via ENUM('none', 'paystack', 'korapay') NOT NULL DEFAULT 'none';
