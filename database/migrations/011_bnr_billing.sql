CREATE TABLE IF NOT EXISTS exchange_rates (
 rate_date TEXT PRIMARY KEY, eur_ron INTEGER NOT NULL CHECK(eur_ron>0),
 fetched_at INTEGER NOT NULL, source_url TEXT NOT NULL, content_hash TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS managed_ron_subscriptions (
 user_id TEXT PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
 subscription_id TEXT NOT NULL UNIQUE, stripe_mode TEXT NOT NULL,
 created_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS invoice_fx_quotes (
 invoice_id TEXT PRIMARY KEY, user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
 subscription_id TEXT NOT NULL, stripe_mode TEXT NOT NULL, snapshot TEXT NOT NULL,
 status TEXT NOT NULL DEFAULT 'pending', attempts INTEGER NOT NULL DEFAULT 0,
 next_attempt_at INTEGER NOT NULL DEFAULT 0, error_code TEXT, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL
);
