-- Leaderboard "All time" period: leadership rewards can be recorded against it.
ALTER TABLE leaderboard_rewards MODIFY period ENUM('week', 'month', 'all') NOT NULL;
