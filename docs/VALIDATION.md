# Validation

## Automated application tests

```sh
composer lint
composer test
composer audit
python3 tests/http_flow.py
```

The PHP suite uses an in-memory database, storage fixtures and signed synthetic Stripe test events. It covers localization parity and eight email types, route mappings, locale negotiation, plan tampering, password hashing, duplicate email, expiry/replay, free/paid activation gates, idempotent provisioning, retry, webhook signature/timestamp checks, invoice persistence, payment failure, cancellation, out-of-order events, CSRF, rate limits and trusted proxy handling. The official SDK's transport is replaced to inspect Checkout/portal request construction. All localized email types are actually rendered. No external service is contacted.

The Python HTTP suite starts an isolated PHP server/database and a local storage API fixture. It follows real registration, scanner-safe email confirmation, secure password activation through the actual cURL adapter, recovery, contact and local email-worker delivery. It checks persisted quotas and absence of plaintext passwords. No production or external storage accounts are touched.

Recorded local validation on 16 October 2026: 109 PHP assertions, 16 HTTP assertions and 692 Chrome assertions passed. PHP syntax, strict Composer validation and dependency advisory audit passed. Docker image built successfully and the Apache container served localized routes. External acceptance below remains outstanding.

## Browser acceptance checks

Start the local server, then install Playwright in your test environment and run:

```sh
node tests/browser.cjs
```

Requires Chrome by default. Set BROWSER_CHANNEL or PLAYWRIGHT_MODULE for your installed browser/runtime; TEST_URL defaults to http://localhost:8088. The test creates a local signup and contact message. Run against a disposable development database with MAIL_TRANSPORT=log, never production.

The matrix checks all 16 public routes at 320, 360, 390, 430, 768, 1024 and 1440 px; locale HTML, alternate tags, horizontal overflow and internal branding. It also checks mobile menu keyboard dismissal, signup step navigation, server plan validation, CSRF/invalid contact submissions, contact success, sitemap, root locale redirect, cookie persistence, no-JavaScript rendering and reduced motion. Screenshots/results are generated in ignored tests/artifacts.

## Required external acceptance before release

These need real test credentials and a reachable isolated storage environment:
1. Verify actual SMTP delivery, spam placement and each language's email rendering in target mail clients.
2. Complete hosted Stripe Checkout in TEST MODE, cancel checkout, fail a renewal, schedule cancellation, verify portal and actual invoice links.
3. Replay signed events via Stripe CLI and verify no duplicate account/email.
4. Provision and reconcile an account against the installed private backend version; inspect quotas, retry after a timeout, verify failed-payment upload restrictions and retained downloads.
5. Set up the approved branded mobile app distribution URLs/service address and verify backup/restore on iOS and Android.
6. Add final legal documents and configure support email.
7. Check actual reverse-proxy TLS, forwarded client IPs, caching, public URLs and Core Web Vitals on representative phones.

Fixtures and a successful local container build do not establish that these external integrations have been accepted.

## Fiscal invoicing update — 17 October 2026

Validated 175 PHP assertions, 27 isolated HTTP assertions, 693 public-site Chrome assertions and 181 billing-flow Chrome assertions. PHP syntax, strict Composer validation, dependency advisory audit and Docker build also passed. No real fiscal invoice was issued.

Fiscal cases cover first purchase, repeated events, each renewal, provider outage, retry after a lost response following remote creation, interrupted workers, immutable billing snapshots, updated future profiles, exact billing periods, actual collected amounts, configured VAT, localized fiscal email delivery/completion, failure/cancellation exclusions, zero collection, unsupported currency/proration and safe simulation. The HTTP test covers paid billing profiles without JavaScript, local persistence, authenticated editing/history, no invoice for free signup and the retry CLI. It uses a disposable database and only local fixture APIs.

The additional browser suite checks paid registration and company fields in all four locales at all seven requested widths, private billing/history at 320/390/768/1440 px, saved company data and anonymous access exclusion. Screenshots are in ignored tests/artifacts/billing-*.png.

To run this optional suite, start an isolated PHP test server with APP_ENV=test, OBLIO_ENABLED=false, a disposable DB_PATH, STRIPE_PRICE_120GB=price_browser and APP_URL matching its local origin. Run tests/setup-billing-browser.php with the same environment and save its JSON to tests/artifacts/billing-fixture.json. Then run tests/billing-browser.cjs with BILLING_FIXTURE_FILE pointing to that JSON, TEST_URL matching the test server (default http://localhost:8089) and PLAYWRIGHT_MODULE if needed. The fixture creates only local test data and refuses non-test environments. Regenerate its one-use verification link before rerunning the browser suite.

Real Oblio authentication, issuer nomenclatures, numbering, document/PDF/e-Factura output and hosted Stripe test payment acceptance still require controlled external acceptance. See [Oblio deployment and recovery](OBLIO.md). Refund/storno automation remains phase 2.
