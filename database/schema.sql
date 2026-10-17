PRAGMA foreign_keys=ON;
CREATE TABLE IF NOT EXISTS users(
 id TEXT PRIMARY KEY, name TEXT NOT NULL, email TEXT NOT NULL UNIQUE COLLATE NOCASE,
 password_hash TEXT NOT NULL, locale TEXT NOT NULL, selected_plan_id TEXT NOT NULL,
 email_verified_at INTEGER, storage_user_id TEXT UNIQUE, storage_quota_bytes INTEGER,
 status TEXT NOT NULL DEFAULT 'pending', terms_at INTEGER NOT NULL, privacy_at INTEGER NOT NULL,
 created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS email_verifications(
 token_hash TEXT PRIMARY KEY,user_id TEXT NOT NULL REFERENCES users(id), expires_at INTEGER NOT NULL, used_at INTEGER
);
CREATE TABLE IF NOT EXISTS subscriptions(
 user_id TEXT PRIMARY KEY REFERENCES users(id), stripe_customer_id TEXT,
 stripe_subscription_id TEXT UNIQUE, stripe_price_id TEXT, status TEXT NOT NULL DEFAULT 'pending',
 plan TEXT, renewal_at INTEGER, last_invoice_id TEXT, paid_confirmed INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS billing_events(id TEXT PRIMARY KEY,type TEXT NOT NULL,status TEXT NOT NULL,created_at INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS invoices(id TEXT PRIMARY KEY,user_id TEXT NOT NULL REFERENCES users(id),hosted_url TEXT,amount INTEGER,currency TEXT,created_at INTEGER);
CREATE TABLE IF NOT EXISTS provisioning_events(id INTEGER PRIMARY KEY,user_id TEXT NOT NULL,action TEXT NOT NULL,status TEXT NOT NULL,created_at INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS contact_messages(id TEXT PRIMARY KEY,name TEXT NOT NULL,email TEXT NOT NULL,subject TEXT NOT NULL,message TEXT NOT NULL,locale TEXT NOT NULL,created_at INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS rate_limits(bucket TEXT PRIMARY KEY,hits INTEGER NOT NULL,expires_at INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS outbox(id INTEGER PRIMARY KEY,dedupe_key TEXT UNIQUE NOT NULL,recipient TEXT NOT NULL,locale TEXT NOT NULL,kind TEXT NOT NULL,payload TEXT NOT NULL,sent_at INTEGER,attempts INTEGER NOT NULL DEFAULT 0,created_at INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS checkout_sessions(user_id TEXT PRIMARY KEY,session_id TEXT NOT NULL,url TEXT NOT NULL,expires_at INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS notices(user_id TEXT NOT NULL,kind TEXT NOT NULL,period TEXT NOT NULL,PRIMARY KEY(user_id,kind,period));
