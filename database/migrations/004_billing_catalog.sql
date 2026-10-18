CREATE TABLE IF NOT EXISTS billing_prices (
 price_id TEXT PRIMARY KEY, plan TEXT NOT NULL, interval TEXT NOT NULL CHECK(interval IN ('month','year')),
 currency TEXT NOT NULL, amount INTEGER, product_id TEXT, status TEXT NOT NULL CHECK(status IN ('active','legacy')),
 created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS active_billing_price ON billing_prices(plan,interval) WHERE status='active';
CREATE TABLE IF NOT EXISTS billing_products (plan TEXT PRIMARY KEY,product_id TEXT NOT NULL,updated_at INTEGER NOT NULL);
