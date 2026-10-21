# Native RON pricing and billing

Configure existing packages at **Configurare → Pachete și prețuri** (/admin?section=plans). Names and integer GB capacities remain editable. Enter monthly prices in RON with a comma or point and up to two decimals. Paid packages accept 2.00–9999.99 RON; Free stays zero. Annual RON prices are monthly × 11: twelve months for eleven monthly payments.

The shipped RON defaults are 21.26 / 38.31 / 63.89 monthly. Existing installations migrate their actual saved EUR references using the latest saved BNR rate, so their resulting RON amounts may differ from fresh-install defaults. Migration preserves package names and GB overrides.

## Public display and payment

RO shows the native RON price as the primary amount, with indicative EUR below. EN/FR/DE show EUR first, with the native RON below. Monthly/yearly switching and registration labels use the same display helper, including without JavaScript.

BNR source: https://curs.bnr.ro/nbrfxrates10days.xml. The administration exchange page shows rate, publication date and refresh control. EUR is calculated by dividing integer RON bani by the scaled RON-per-EUR rate and rounding to EUR cents. No markup is added. Updating BNR changes display equivalents only: it does not change RON charges, invalidate a confirmed RON quote or create replacement Stripe Prices.

If BNR is unavailable, the native RON amount and Checkout remain available. EUR is marked unavailable once no usable recent rate exists. A changed package amount/capacity requires a fresh Checkout confirmation.

All new Stripe Prices, payments, upgrades and Oblio products/invoices are in RON. Checkout explicitly disables Adaptive Pricing so Stripe does not switch the charge currency for foreign visitors. Any configured VAT is added separately at Checkout. EUR display values are not invoice currency or a contractual exchange-rate adjustment.

## Stripe and existing customers

Saving a package synchronizes both configured TEST/LIVE catalogs without switching the active environment. Stable lookup keys include RON amount, billing policy and capacity, not the current FX display rate. Capacity-only changes create separate Product/Price versions. Disabled options stay disabled and replaced Price IDs remain recognizable.

New subscriptions use ordinary Stripe recurring collection at the purchased fixed RON price. There is no pause_collection or draft FX adjustment for these contracts. Existing subscriptions retain purchased capacity and their original billing policy. Historical bnr_v1 contracts continue their agreed EUR-to-RON renewal worker; historical EUR subscriptions are not silently converted. Explicit upgrades into the new catalog use the full RON price difference within the original five-day window, preserve the anniversary and switch future renewals to the new fixed RON Price.

Capacity snapshots continue to protect activation, webhook retries, admin quota sync, unban and usage statistics. Free/manual grants preserve their assigned capacity. Issued invoices are never rewritten.

## One-time migration

Take and verify a consistent SQLite backup and prebuild the new image before deployment. Refresh BNR in the currently installed application. Stop the web/worker processes before running the monetary migration, then start only the RON-aware release: old EUR catalog code must never serve the converted database. This short maintenance window prevents either code version from interpreting the other version’s units. CatalogCurrencyMigration atomically converts old editable EUR rows to RON, fills missing package overrides from the prior defaults and records original settings plus conversion date/rate under catalog:ron-primary-v1. Re-running migrate does not convert again.

The migration does not purge users, invoices, provider records or historical Price mappings. Existing rows retain their bnr_v1 policy; newly synchronized Prices use ron_fixed_v1. A stale EUR admin form is rejected so it cannot save an EUR number as RON after deployment. If an old installation has no valid stored rate, fx:update followed by migrate resumes the conversion; no monetary fallback is invented.

Synchronize both configured Stripe catalogs after migration. The existing operations/mail worker remains required for fiscal/email queues and historical FX contracts; fresh RON subscriptions are renewed by Stripe.

## Validation and recovery

Run composer lint, composer test, python3 tests/http_flow.py, python3 tests/admin_http.py, tests/plan-editor-browser.cjs and tests/bnr-ron-browser.cjs. Coverage includes inverse conversion, locale ordering, fixed RON Checkout during FX outage, quote stability under rate changes, migration idempotency, immutable billing history, native Stripe Price reuse and purchased capacity preservation.

Record the previous Git commit/image and use VACUUM INTO with PRAGMA quick_check for a consistent database backup. Do not deploy EUR-only catalog code over migrated RON rows: it would reinterpret amounts. Likewise do not deploy pre-capacity-versioning code after GB edits. Use a compatible release or a separately reviewed recovery that accounts for payments/settings changed since backup; do not restore a stale database over newer provider transactions.
