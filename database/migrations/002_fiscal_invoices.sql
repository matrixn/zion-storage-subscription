CREATE TABLE IF NOT EXISTS billing_profiles (
    id TEXT PRIMARY KEY,
    user_id TEXT NOT NULL UNIQUE REFERENCES users(id),
    type TEXT NOT NULL CHECK(type IN ('individual','company')),
    name TEXT NOT NULL,
    company_name TEXT NOT NULL DEFAULT '',
    tax_id TEXT NOT NULL DEFAULT '',
    registration_number TEXT NOT NULL DEFAULT '',
    country TEXT NOT NULL,
    county TEXT NOT NULL,
    city TEXT NOT NULL,
    address TEXT NOT NULL,
    postal_code TEXT NOT NULL,
    phone TEXT NOT NULL DEFAULT '',
    billing_email TEXT NOT NULL,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS fiscal_invoices (
    id TEXT PRIMARY KEY,
    user_id TEXT NOT NULL REFERENCES users(id),
    stripe_invoice_id TEXT NOT NULL UNIQUE,
    stripe_subscription_id TEXT NOT NULL,
    stripe_customer_id TEXT NOT NULL,
    stripe_payment_intent_id TEXT,
    oblio_invoice_id TEXT,
    oblio_series TEXT,
    oblio_number TEXT,
    invoice_url TEXT,
    billing_period_start INTEGER,
    billing_period_end INTEGER,
    amount INTEGER NOT NULL,
    currency TEXT NOT NULL,
    plan TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending'
      CHECK(status IN ('pending','processing','issued','failed','cancelled','simulated','no_charge')),
    idempotency_key TEXT NOT NULL UNIQUE,
    source_snapshot TEXT NOT NULL,
    request_snapshot TEXT,
    simulation_reference TEXT,
    error_message TEXT,
    attempts INTEGER NOT NULL DEFAULT 0,
    next_attempt_at INTEGER,
    issued_at INTEGER,
    notification_queued_at INTEGER,
    notification_sent_at INTEGER,
    completed_at INTEGER,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS fiscal_invoices_retry ON fiscal_invoices(status,next_attempt_at);
CREATE INDEX IF NOT EXISTS fiscal_invoices_user ON fiscal_invoices(user_id,created_at);
-- Phase 2: a correction references its original document; originals are never deleted.
CREATE TABLE IF NOT EXISTS fiscal_corrections (
    id TEXT PRIMARY KEY,
    original_fiscal_invoice_id TEXT NOT NULL REFERENCES fiscal_invoices(id),
    stripe_refund_id TEXT UNIQUE,
    status TEXT NOT NULL DEFAULT 'needs_review',
    corrective_series TEXT,
    corrective_number TEXT,
    source_snapshot TEXT,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
);
