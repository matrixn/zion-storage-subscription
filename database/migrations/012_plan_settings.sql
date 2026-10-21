CREATE TABLE IF NOT EXISTS plan_settings (
 slug TEXT PRIMARY KEY,
 name TEXT NOT NULL,
 monthly_price INTEGER NOT NULL CHECK(monthly_price>=0 AND monthly_price<=999999),
 updated_at INTEGER NOT NULL
);
