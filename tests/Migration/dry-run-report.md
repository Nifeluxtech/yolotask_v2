# v1 -> v2 import: local dry-run (2026-11-01)

Local MySQL 8.4 with all 14 v2 migrations, one pre-existing v2 user sharing a v1 email (merge test).
Command: `php scripts/import-v1.php --bundle=v1-bundle.json [--dry-run]` (single transaction; refuses to run twice).

| Item | v1 | v2 after import |
|---|---|---|
| Users | 328 | 327 created + 1 merged into existing v2 account |
| bcrypt ($2a$) hashes copied | 328 | 327 (merged user keeps v2 password) — password_verify confirmed |
| Verified emails | 328 | 327 + merged |
| Wallet available | ₦8,649,671,312.85 | ₦8,649,671,312.85 (opening-balance ledger tx each) |
| Earner available | ₦31,723.35 | ₦31,733.35 (merged user's v1 balance) |
| Campaigns | 22 | 15 live, 2 paused, 2 completed, 3 draft |
| Escrow | ₦1,408,040 locked | ₦1,408,040 |
| Submissions | 1,358 / 112 / 97 | approved / rejected / pending, same |
| Pending proofs | 97 files | 97 copied to private storage |
| Bank accounts | 57 | 57, all enc2: sealed |
| Referrals | 132 | 132 active, 38 v1 milestones marked reached (no double bonus) |
| Notifications / announcements | 1,621 / 7 | same (announcements marked delivered) |
| Interests | 1,107 links | 1,107 (37 interests added) |
| Archived (v1_archive) | ledger 3,284, withdrawals 20, top-ups 21, check-ins 3, SMS 13 | kept as history, not replayed |

Reconciliation: Ledger, withdrawal fees, referrals, check-ins, deposit cashback — 0 problems.

Decisions: big balances carried as-is (owner, 2026-11-01). v1 SUBMITTED campaigns (never charged) -> draft.
Pending submissions auto-approve no earlier than 24h after import. Referral codes kept (YOLO-xxxx links still work).
Merged accounts keep their v2 role and password (v1 admin globalpaysure@gmail.com merged as earner in test only).
