# Zion Storage

A small server-rendered PHP application for public marketing, registration, verified activation, monthly subscriptions and mobile onboarding. No browser photo gallery.

## Local development

Requires PHP 8.4 with PDO SQLite, mbstring, curl, sodium and OpenSSL, plus Composer.

```sh
composer install
cp .env.example .env
php bin/console.php key
# Put the generated value into APP_KEY in .env.
# Set ADMIN_EMAIL and ADMIN_PASSWORD in .env to create the initial administrator.
php bin/console.php install
php -S 127.0.0.1:8088 -t public public/router.php
```

Open http://localhost:8088/ro/ (or /en/, /fr/, /de/). Keep .env private and never commit it. Source repository: https://github.com/matrixn/zion-storage-subscription.

Administration is available at `/admin`: create manual customers, manage plans and suspensions, inspect/retry upgrades, and view the operator audit trail. Paid customer upgrades collect the one-time price difference during the original five-day window and preserve the monthly renewal anchor. See [administration, installation, upgrade deadlines and suspension gateway](docs/ADMIN-AND-UPGRADES.md).

Run `php bin/console.php mail:work` to deliver queued email. Local `MAIL_TRANSPORT=log` renders emails under private `storage/mail/`; no messages are sent externally. Open these files locally to follow verification links. Do not publish this folder.

## Structure

- `public/`: document root, front controller, local CSS/SVG and small progressive-enhancement script.
- `src/`: routing, localization, controllers, database, account security and service adapters.
- `templates/`: shared localized layouts; templates are not duplicated by locale.
- `translations/`: complete RO/EN/FR/DE dictionaries, including fiscal invoice notifications.
- `config/plans.php`: canonical storage/price catalog; integer minor currency units and decimal storage bytes.
- `database/schema.sql`: idempotent initial schema.
- `bin/console.php`: migration, private diagnostics, email worker and maintenance.
- `tests/`: business/security tests and browser acceptance checks.

Four marketing pages per locale: home, how it works, register, contact. Pricing and FAQ stay on home. Verification, checkout, activation, recovery and legal routes are supporting flows and noindex. Sitemap lists exactly the 16 marketing URLs with alternates.

## Activation and password decision

Registration stores an Argon2id hash only. No clear-text or reversibly encrypted password is persisted. After verification and, for paid plans, confirmed subscription payment, the user confirms the original password in a POST form. It is verified locally and passed over the private server-side API connection once. This is architecture B from the brief. Account creation therefore needs this final user interaction; it cannot silently happen in a webhook without retaining a recoverable password.

Verification tokens contain 32 random bytes, are hashed with SHA-256, expire after one hour and are consumed atomically. GET only presents a confirmation form, protecting against mail-link scanners. Email queue payloads and fiscal snapshots are authenticated-encrypted using APP_KEY. Sent email payloads are scrubbed; fiscal audit snapshots remain. Preserve APP_KEY with backups and do not rotate it without a re-encryption migration.

Verified users can resume signup through a new one-use email link. The secure session expires after four hours. Email links never contain passwords. Existing-email registration and resend use generic responses. Verification resend is limited per IP and email. Storage creation is serialized per user and reconciled using a safe opaque label after timeouts.

## Billing (TEST MODE)

Only Stripe test keys and non-live webhook events are accepted in this release. No live billing was enabled.

1. Create three recurring monthly RON Prices in Stripe test mode: 999 (120 GB), 1999 (250 GB), 2999 (500 GB) minor units. Map the matching STRIPE_PRICE_* settings to these prices; use new Price IDs when changing amounts. The 1 TB plan is no longer offered.
2. Set the corresponding STRIPE_PRICE_* values and STRIPE_SECRET_KEY in .env.
3. Register `/webhooks/stripe`, configure STRIPE_WEBHOOK_SECRET and subscribe to:
   - checkout.session.completed
   - invoice.paid
   - invoice.payment_failed
   - customer.subscription.created
   - customer.subscription.updated
   - customer.subscription.deleted
4. The application creates a restricted Stripe portal configuration for payment method updates, cancellation and invoice history, with plan changes disabled. Enable billing invoice/receipt emails in Stripe.
5. Use Stripe CLI forwarding for localhost: `stripe listen --forward-to localhost:8088/webhooks/stripe`. Use the emitted signing secret.
6. Register a paid account, verify email, complete Checkout using Stripe's documented test cards. Test failure and cancellation as well.

The return URL does not grant access. Webhooks verify the official SDK signature/timestamp and retrieve the current subscription with expanded latest invoice. Access requires the matching server-configured price, a paid latest invoice, active subscription and a verified email. Event order cannot restore an obsolete paid state. Database event IDs, email deduplication and locks support webhook retries. Subscription quota changes must be made through this application's configured catalog; arbitrary price changes in the Stripe dashboard are rejected.

Stripe invoices are recorded as payment/billing references. The customer portal manages payment methods and renewals. Fiscal invoicing is provided separately by `OblioInvoiceService`, with durable per-cycle jobs, local individual/company billing profiles, invoice history and localized notifications. A Stripe receipt is not the final fiscal invoice. Oblio defaults to local simulation; credentials, issuer/VAT settings and controlled external acceptance are required before real issuance. See [Oblio configuration and retry workflow](docs/OBLIO.md).

## Private storage adapter (operator-only information)

`StorageProvisioningService` integrates the Immich admin API, based on its official OpenAPI schema:
https://github.com/immich-app/immich/blob/main/open-api/immich-openapi-specs.json

Set STORAGE_API_URL to the API root **including /api** and STORAGE_API_KEY to an administrator API key with user read/create/update privileges. Use a dedicated key and restrict network access. Test against the installed backend version before deployment; the exact deployed version and credentials were not supplied.

Implemented calls:
- GET /admin/users?withDeleted=true — safe-label reconciliation.
- POST /admin/users — email, name, password, quotaSizeInBytes, storageLabel, isAdmin=false, notify=false.
- PUT /admin/users/{id} — quota update.
- GET /admin/users/{id} — quota/usage.
- DELETE /admin/users/{id} with force=false — available only as a service method, never called by billing.

The API does not provide a general non-destructive account-disabled switch. `disableUser` restricts **new uploads** by setting quota to current usage (minimum one byte); it deliberately retains sign-in and downloads. `enableUser` restores the subscribed quota. Cancellation never calls delete or restore APIs, which could schedule data removal. Account bans use a separate flag and the supplied [private proxy gateway](docs/ADMIN-AND-UPGRADES.md#suspend-without-deleting-files) to block authenticated API/file access without deleting users.

New uploads stop when a failed/inactive subscription is reconciled; ending renewal at period end keeps access while Stripe still reports an active paid subscription. There is no automatic data deletion policy or deletion scheduler.

## Onboarding and branding

MOBILE_SERVICE_URL is the public mobile service address. MOBILE_IOS_URL and MOBILE_ANDROID_URL should point to approved Zion-branded distribution/instructions. They are optional and hidden when empty. Do not link to a third-party branded application while promising a completely standalone brand. Mobile app repackaging/distribution is outside this website and remains an operator prerequisite. No internal API URL is returned in public HTML.

The provided wordmark, icon, favicon, 512px application-icon placeholder and 1200×630 PNG OG image are original. Editable SVG sources are included. Local system fonts and CSS-drawn photo illustrations avoid remote assets and tracking.

## Legal and launch configuration

Place approved localized plain-text legal documents in `legal/{ro,en,fr,de}/{privacy,terms,cookies}.txt`. They are escaped and displayed on supporting noindex routes. Set LEGAL_READY=true after all twelve documents are installed. Production registration refuses acceptance of unpublished terms; development signup remains available for testing. No legal or privacy guarantees beyond the supplied product claims are invented.

Optional CAPTCHA uses Turnstile: set CAPTCHA_ENABLED=true, CAPTCHA_SITE_KEY, CAPTCHA_SECRET and the provided verification endpoint. Keep it off without configured keys. Server-side CSRF, honeypot and rate limits operate independently.

See [deployment](docs/DEPLOYMENT.md) and [validation](docs/VALIDATION.md). Do not use PHP's development server for public traffic.
