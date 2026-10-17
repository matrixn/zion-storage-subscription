CREATE TABLE IF NOT EXISTS administrators (
 id TEXT PRIMARY KEY, email TEXT NOT NULL UNIQUE COLLATE NOCASE, password_hash TEXT NOT NULL, created_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS integration_settings (key TEXT PRIMARY KEY,value TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS account_controls (
 user_id TEXT PRIMARY KEY REFERENCES users(id), banned_at INTEGER, reason TEXT,
 manual_plan TEXT, access_sync TEXT NOT NULL DEFAULT 'ready', quota_sync TEXT NOT NULL DEFAULT 'ready', updated_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS subscription_origins (
 stripe_subscription_id TEXT PRIMARY KEY, user_id TEXT NOT NULL REFERENCES users(id),
 purchased_at INTEGER NOT NULL, original_period_end INTEGER NOT NULL, invoice_id TEXT NOT NULL UNIQUE
);
CREATE TABLE IF NOT EXISTS plan_changes (
 id TEXT PRIMARY KEY, user_id TEXT NOT NULL REFERENCES users(id), kind TEXT NOT NULL,
 stripe_subscription_id TEXT, customer_id TEXT, item_id TEXT,
 from_plan TEXT NOT NULL, to_plan TEXT NOT NULL, from_price TEXT, to_price TEXT,
 amount INTEGER NOT NULL, currency TEXT NOT NULL DEFAULT 'ron', period_end INTEGER,
 deadline INTEGER, checkout_session_id TEXT UNIQUE, checkout_url TEXT, expires_at INTEGER,
 invoice_id TEXT UNIQUE, payment_intent_id TEXT UNIQUE, paid_at INTEGER,
 status TEXT NOT NULL, error_code TEXT, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS one_pending_change ON plan_changes(user_id)
 WHERE status IN ('creating','pending','paid','applying','retry');
CREATE TABLE IF NOT EXISTS admin_audit (
 id INTEGER PRIMARY KEY AUTOINCREMENT, admin_id TEXT NOT NULL REFERENCES administrators(id),
 user_id TEXT REFERENCES users(id), action TEXT NOT NULL, detail TEXT NOT NULL, created_at INTEGER NOT NULL
);
