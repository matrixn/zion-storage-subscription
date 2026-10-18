CREATE TABLE IF NOT EXISTS withdrawal_requests(
 id TEXT PRIMARY KEY,payload TEXT NOT NULL,locale TEXT NOT NULL,created_at INTEGER NOT NULL,
 status TEXT NOT NULL DEFAULT 'new',resolved_at INTEGER,notes TEXT NOT NULL DEFAULT ''
);
