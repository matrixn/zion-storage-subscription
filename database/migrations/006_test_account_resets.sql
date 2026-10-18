CREATE TABLE IF NOT EXISTS test_account_resets (
 user_id TEXT PRIMARY KEY, admin_id TEXT NOT NULL REFERENCES administrators(id),
 status TEXT NOT NULL CHECK(status IN ('pending','complete')), step TEXT NOT NULL,
 snapshot TEXT NOT NULL, error_code TEXT, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL
);
-- Only opaque hashes survive deletion, to acknowledge delayed TEST callbacks.
CREATE TABLE IF NOT EXISTS reset_resource_hashes (
 resource_hash TEXT PRIMARY KEY, user_id TEXT NOT NULL REFERENCES test_account_resets(user_id)
);
