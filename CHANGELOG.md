# Changelog

## 2026-10-17

- Added separate individual/company billing profiles to paid registration and authenticated account flows in RO/EN/FR/DE.
- Added durable Oblio fiscal invoice jobs keyed by Stripe invoice, provider-side idempotency, encrypted immutable snapshots and retry/inspection CLI tools.
- Added configurable fiscal metadata/VAT, exact covered periods and actual collected Stripe amounts.
- Added localized fiscal invoice notifications and private document history; Stripe payment receipts remain distinct from fiscal invoices.
- Added safe default-off simulation and independent payment/fiscal completion states.
- Prepared linked corrective-document records; automatic refunds/storno remain phase 2.
