CREATE TABLE IF NOT EXISTS immich_statistics(
 user_id TEXT PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
 storage_user_id TEXT NOT NULL,
 used_bytes INTEGER NOT NULL,
 photos INTEGER NOT NULL,
 videos INTEGER NOT NULL,
 remote_quota_bytes INTEGER,
 synced_at INTEGER NOT NULL,
 config_hash TEXT NOT NULL,
 available INTEGER NOT NULL DEFAULT 1
);
CREATE INDEX IF NOT EXISTS immich_statistics_usage ON immich_statistics(config_hash,used_bytes DESC);
