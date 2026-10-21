# EUR reference prices and RON billing

The central catalog in config/plans.php defines Free 5 GB, Personal 120 GB, Plus 250 GB and Titan 500 GB. Titan retains the 500gb identifier and provides one account.

| Plan | Monthly EUR | Annual EUR |
| --- | ---: | ---: |
| Personal | 3.99 | 43.89 |
| Plus | 7.19 | 79.09 |
| Titan | 11.99 | 131.89 |

Annual billing charges once for twelve months at eleven monthly prices. The public switcher displays EUR reference amounts with RON equivalents in RO/EN/FR/DE. Checkout charges RON, with the exact amount confirmed before redirect. A changed quote requires another confirmation; browser amounts are never trusted.

## Configuration

Install/migrate and sign into /admin. Open **Configurare → Curs valutar BNR** (/admin?section=exchange) and refresh the rate, then synchronize the independent Stripe TEST/LIVE catalogs. API credentials and six monthly/yearly Price mappings remain database-managed. Stable Product IDs, lookup keys and idempotency keys support safe retries. Disabled options stay disabled.

The fixed official source is https://curs.bnr.ro/nbrfxrates10days.xml. The administration page shows publication date, retrieval time and source. The last published banking-day rate is used over weekends/holidays, with a maximum seven-day gap. Quotes require a successful recent fetch; failures block new payments rather than inventing a rate. Integer arithmetic rounds once to RON bani, with no FX markup. Public page views never call BNR or Stripe.

The worker refreshes the source at most every 30 minutes and Checkout checks again. The admin refresh button also synchronizes both configured Stripe profiles. Each Price stores its EUR reference and conversion snapshot; historical IDs remain recognizable. Keep the full SQLite database in backups.

## Edit package names and EUR prices

Open **Configurare → Pachete și prețuri** (/admin?section=plans). Every existing package can be renamed and assigned an integer capacity from 1 to 1,000,000 GB (decimal GB = 1,000,000,000 bytes). Paid monthly EUR references accept a comma or point and up to two decimal places, from 1.00 to 9999.99 EUR. Free remains zero. Annual references are always monthly × 11; there is no independent annual-price override. Slug and recommendation remain unchanged.

Edits are stored in plan_settings and audited with before/after values. Saving updates public pages immediately and synchronizes both configured Stripe profiles without changing the active mode. Name changes update existing Stripe Products even when their Prices already match; amount changes create/reuse immutable Prices and retain historical mappings. Disabled options remain disabled. If BNR/Stripe is unavailable, the edit remains saved and the notice explains the pending sync; use Catalog Stripe / Curs BNR to retry.

Existing subscriptions retain both their EUR amount and purchased GB capacity, including all renewals. A new capacity applies only to new subscriptions or an explicitly purchased change to a different plan. Changing the catalog does not resize active accounts. Price mappings freeze storage_bytes; a capacity-only edit creates new monthly/yearly Prices and a separate Stripe Product for that capacity, preserving old invoice descriptions. Free and manually granted accounts retain their stored quota. Activation, quota retries, unban, statistics and paid webhooks use the historical entitlement. Renewal FX conversions use that amount, not the edited public reference. Issued invoices retain their snapshots. Previously displayed checkout quotes must be reconfirmed when the amount changes.

## Renewals and upgrades

Run php bin/console.php operations:work every minute (the provided Compose worker does this automatically). On Synology's existing web-only installation, the scheduled command must execute it inside zion_storage_web as www-data. Monitor /admin?section=exchange and the API journal.

New BNR-managed subscriptions use Stripe pause_collection.behavior=keep_as_draft. This keeps the subscription active while preventing unattended collection at an old conversion rate. Each renewal freezes the published BNR rate available for its service-period start. The worker validates the original recurring line, adds a positive or negative FX adjustment when necessary, verifies taxes/totals, finalizes and collects. Retry uses the same frozen quote and idempotency keys. An outage leaves an invoice awaiting retry; payment success and entitlement still require the authoritative invoice.paid reconciliation.

Subscribe the Stripe webhook destination to invoice.created and invoice.payment_action_required in addition to the existing checkout/subscription/invoice events. Worker discovery also finds draft renewals if invoice.created is missed. Customer action required by the bank is reported by email.

Customer upgrades remain limited to five days from initial purchase, a larger plan and the same interval. The EUR difference is converted and frozen for the one-time RON charge. Future renewals use the larger EUR reference at each cycle's BNR rate, preserving the original anniversary.

## Existing subscriptions and fiscal records

Existing EUR subscriptions cannot be changed directly to RON. Their paid amounts, currency and fiscal records remain unchanged; enrolling in a new RON subscription is required. The application does not cancel or re-enroll existing customers automatically. Historical environment mappings are imported once; do not delete mappings while old events can arrive.

Oblio receives actual collected amounts in RON. Its visible mentions include the EUR reference, BNR rate/date and RON equivalent. Romanian city/county fields are normalized; known rejected requests can be rebuilt from a corrected customer profile, while uncertain provider outcomes retain their original snapshot. Issued documents are never silently rewritten.

## Validation and recovery

Run composer lint, composer test, python3 tests/http_flow.py, python3 tests/admin_http.py and tests/bnr-ron-browser.cjs with Playwright. Coverage includes frozen quotes, retry after lost responses, upward/downward FX adjustments, tax rounding, foreign invoice rejection, address normalization, localization and stale-quote confirmation. Isolated Stripe TEST API acceptance checks validated held drafts and both signs of adjustment without attempting a payment; actual bank authentication and the full live payment flow require a real customer transaction.

Before release, take a consistent SQLite backup using VACUUM INTO and verify PRAGMA quick_check; record the old Git commit and Docker image. Schema additions are backward compatible. Rollback application/image first without restoring a database containing newer payments. If RON subscriptions have been enrolled, keep their draft holds in place and restore the RON worker promptly; older code cannot collect those renewals correctly. Restore a database backup only as a separately reviewed recovery action, after accounting for intervening provider transactions.
