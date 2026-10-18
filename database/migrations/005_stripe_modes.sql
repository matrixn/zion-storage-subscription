CREATE TABLE IF NOT EXISTS stripe_catalog_products (
 plan TEXT NOT NULL, stripe_mode TEXT NOT NULL CHECK(stripe_mode IN ('test','live')),
 product_id TEXT NOT NULL, updated_at INTEGER NOT NULL, PRIMARY KEY(plan,stripe_mode)
);
INSERT OR IGNORE INTO stripe_catalog_products(plan,stripe_mode,product_id,updated_at)
 SELECT plan,'test',product_id,updated_at FROM billing_products;
