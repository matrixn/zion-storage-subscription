CREATE TABLE IF NOT EXISTS activation_credentials(
 user_id TEXT PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
 payload TEXT NOT NULL,
 expires_at INTEGER NOT NULL,
 next_attempt_at INTEGER NOT NULL DEFAULT 0
);
