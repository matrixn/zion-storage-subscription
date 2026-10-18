# EUR pricing and billing catalog

The central catalog in config/plans.php defines Free 10 GB, Personal 120 GB, Plus 250 GB and Titan 500 GB. Titan retains the 500gb identifier and provides one account.

| Plan | Monthly EUR | Annual EUR |
| --- | ---: | ---: |
| Personal | 1.99 | 21.89 |
| Plus | 3.59 | 39.49 |
| Titan | 5.99 | 65.89 |

Annual billing charges the displayed total once per year: twelve months of storage for eleven monthly payments. The home switcher and registration interval selector work with server rendering and JavaScript. Every registration persists its chosen interval; Checkout uses the corresponding server-side Price, never a browser-provided amount. RO/EN/FR/DE are supported.

## Configure through /admin

Run php bin/console.php install, then sign into /admin with the administrator seeded from ADMIN_EMAIL and ADMIN_PASSWORD. Save Stripe secret/webhook credentials in its API settings section; this disables environment fallback for Stripe. In the catalog, either validate/save an existing Price ID or create the matching TEST Product/Price for each of the six paid options. Creation uses one Product per plan and separate monthly/yearly Prices. Stable product IDs, lookup keys and idempotency keys allow retries to reuse created objects. See [API configuration](API-SETTINGS.md).

Validation requires TEST MODE, active Price, exact EUR minor-unit amount and recurring interval/count. Checkout validates again before issuing a session. Replacing/disabling a mapping affects new purchases only; historical IDs remain recognizable. Catalog actions require admin authentication and CSRF and are audited. Keep billing_prices and billing_products in database backups.

This release accepts TEST MODE only. No real Stripe account or hosted payment was contacted during local automated validation; SDK fixtures validate requests and responses. Credentials and actual hosted Checkout totals require operator acceptance.

## Existing subscriptions

Migration imports previous STRIPE_PRICE_* / STRIPE_LEGACY_PRICE_* environment values once as legacy monthly RON mappings, plus known local subscription/change IDs. Future configuration is through /admin; add any additional historical IDs through its history form. Do not delete legacy mappings while old events/invoices can arrive. Environment IDs are no longer runtime configuration.

Existing subscriptions, paid amounts, currencies and fiscal records are preserved. No automatic RON-to-EUR conversion or repricing occurs. Legacy prices cannot use ordinary customer upgrades against the new EUR catalog. Already-open initial Checkout quotes retain their original terms and block a new conflicting quote until resolved or expired.

Customer upgrades remain limited to the original five-day window, a larger plan and the same currency/interval. Personal to Plus costs 1.60 EUR for monthly billing or 17.60 EUR for yearly billing, once. Future renewals use the larger plan at the original renewal date. Admin paid plan changes also preserve the interval and anchor.

Oblio receives actual EUR payments and full Stripe invoice periods; annual service names identify annual billing. Historical RON documents remain RON. Oblio currency support includes EUR/RON; issuer/exchange-rate settings and real provider acceptance must be verified before issuance. The application does not invent an exchange rate.

## Validation

Run composer test, composer lint, python3 tests/http_flow.py, python3 tests/admin_http.py and the pricing/carousel browser scripts with Playwright and Chrome. Coverage includes catalog validation/history, retry-safe Product/Price creation, annual webhook/fiscal capture and same-anniversary paid upgrades. Browser coverage includes four locales, responsive sizes, switcher/registration interval propagation, no-JavaScript fallback and reduced motion.
