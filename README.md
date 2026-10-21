# Zion Storage

A small server-rendered PHP application for public marketing, registration, verified activation, monthly/yearly subscriptions and mobile onboarding. No browser photo gallery.

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

Administration is available at `/admin`: create manual customers, manage plans and suspensions, inspect/retry upgrades, and view the operator audit trail. Paid customer upgrades collect the one-time price difference during the original five-day window and preserve the existing renewal date and billing interval. See [administration, installation, upgrade deadlines and suspension gateway](docs/ADMIN-AND-UPGRADES.md).

Configure **Email / Listmonk** in `/admin?section=listmonk` for transactional API delivery, connection testing and masked encrypted credentials; see [Listmonk setup](docs/LISTMONK.md). Docker Compose includes an automatic worker for due Oblio invoices and queued mail. On the existing Synology web-container layout, schedule `docker exec --user www-data zion_storage_web php /var/www/html/bin/console.php operations:work` every minute to process RON renewals, fiscal jobs and queued mail. For non-Docker installations, schedule `php bin/console.php operations:work` every minute for these queues; `mail:work` is still available for email only. Local `MAIL_TRANSPORT=log` renders emails under private `storage/mail/`; no messages are sent externally. Open these files locally to follow verification links. Do not publish this folder.

## Structure

The admin dashboard summarizes clients, subscriptions, receipts by currency, upcoming renewals and operational queues. A vertical menu with at most two levels separates all management forms, billing catalog, API settings and audit views. See [dashboard metrics and navigation](docs/ADMIN-DASHBOARD.md).

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

Registration stores an Argon2id authentication hash and a separate APP_KEY-encrypted onboarding password for at most 24 hours. After verified email and, for paid plans, authoritative payment confirmation, storage activation runs automatically using that temporary credential. Successful creation deletes the encrypted credential immediately. The authenticated status page updates automatically; the every-minute `changes:retry` worker also processes activation when the browser is closed. Webhook acknowledgement does not wait for account creation. Existing accounts or expired credentials require one password confirmation, after which outages can be retried automatically. See [deployment and retention details](docs/DEPLOYMENT.md#automatic-storage-activation).

Verification tokens contain 32 random bytes, are hashed with SHA-256, expire after one hour and are consumed atomically. GET only presents a confirmation form, protecting against mail-link scanners. Email queue payloads and fiscal snapshots are authenticated-encrypted using APP_KEY. Sent email payloads are scrubbed; fiscal audit snapshots remain. Preserve APP_KEY with backups and do not rotate it without a re-encryption migration.

Verified users can resume signup through a new one-use email link. The secure session expires after four hours. Email links never contain passwords. Existing-email registration and resend use generic responses. Verification resend is limited per IP and email. Storage creation is serialized per user and reconciled using a safe opaque label after timeouts.

## Billing (TEST / LIVE)

Separate Stripe TEST and LIVE profiles are configured in /admin. TEST remains the initial active mode. Saving credentials automatically verifies API/catalog access and synchronizes products/prices; switching the public mode is a separate, validated administrator action. No real LIVE billing was enabled during development.

1. Install/migrate the database and sign into /admin. Configure the Stripe API key and webhook signing secret in **API-uri Stripe și Oblio**, selecting TEST or LIVE. After saving a service, all its .env settings are ignored; see [API settings](docs/API-SETTINGS.md).
2. Refresh the official BNR rate under /admin?section=exchange. Saving the Stripe profile creates/reuses six RON recurring Prices from EUR references: Personal 3.99/month or 43.89/year, Plus 7.19/month or 79.09/year, Titan 11.99/month or 131.89/year. Annual billing gives one month free. Edit package names and monthly EUR references under /admin?section=plans; annual is always monthly × 11. Existing subscription contracts remain unchanged. Product/Price mappings live in the database, not .env. See [pricing configuration and transition](docs/PRICING.md).
3. Register separate `/webhooks/stripe?mode=test` and `?mode=live` endpoints, save each signing secret in the corresponding admin profile and subscribe to:
   - checkout.session.completed
   - invoice.paid
   - invoice.payment_failed
   - customer.subscription.created
   - customer.subscription.updated
   - customer.subscription.deleted
4. The application creates a restricted Stripe portal configuration for payment method updates, cancellation and invoice history, with plan changes disabled. Enable billing invoice/receipt emails in Stripe.
5. Use Stripe CLI forwarding for localhost: `stripe listen --forward-to localhost:8088/webhooks/stripe`. Use the emitted signing secret.
6. Register a paid account, verify email, complete Checkout using Stripe's documented test cards. Test failure and cancellation as well.

Paid catalog prices exclude VAT. Configure the global rate under `/admin?section=tax` (unsaved default 0 for the declared non-VAT-registered operator; saved rates are preserved); Stripe Checkout adds any configured VAT separately. Public cards show the EUR reference and RON equivalent; Checkout confirms the RON total. BNR source/date, manual refresh and renewal diagnostics are under /admin?section=exchange. New RON renewals recalculate at each cycle's published rate; historical EUR subscriptions require new RON enrollment. Tax Rates are managed automatically, without manual IDs. See [VAT configuration and historical billing behavior](docs/API-SETTINGS.md#product-vat).

The return URL does not grant access. Webhooks verify the official SDK signature/timestamp and retrieve the current subscription with expanded latest invoice. Access requires the matching server-configured price, a paid latest invoice, active subscription and a verified email. Event order cannot restore an obsolete paid state. Database event IDs, email deduplication and locks support webhook retries. Subscription quota changes must be made through this application's configured catalog; arbitrary price changes in the Stripe dashboard are rejected.

Stripe invoices are recorded as payment/billing references. The customer portal manages payment methods and renewals. Fiscal invoicing is provided separately by `OblioInvoiceService`, with durable per-cycle jobs, local individual/company billing profiles, invoice history and localized notifications. A Stripe receipt is not the final fiscal invoice. Oblio defaults to local simulation; credentials, issuer/VAT settings and controlled external acceptance are required before real issuance. See [Oblio configuration and retry workflow](docs/OBLIO.md).

## Private storage adapter (operator-only information)

`StorageProvisioningService` integrates the Immich admin API, based on its official OpenAPI schema:
https://github.com/immich-app/immich/blob/main/open-api/immich-openapi-specs.json

Configure the API root and administrator key in `/admin?section=immich`, test the connection and sync statistics. Saved configuration overrides STORAGE_API_URL / STORAGE_API_KEY; those environment values are used only before the first save. The API root includes **/api** and the key needs user read/create/update privileges plus server.statistics for dashboard counts. See [Immich settings and five-minute collection](docs/IMMICH.md). Use a dedicated key and restrict network access. Test against the installed backend version before deployment; the exact deployed version and credentials were not supplied.

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

The lime photo-stack brand icon is generated with ImageGen and exported locally for the site, browser favicons, 180px Apple touch icon, 512px app icon and 1200×630 sharing image. Its 1024px master and generation brief are in `design/brand/`. The compatibility SVG embeds the PNG; it is not an editable vector master. Local system fonts and CSS-drawn photo illustrations avoid remote assets and tracking.

## Legal and launch configuration

Operator-specific localized plain-text documents are provided in `legal/{ro,en,fr,de}/{privacy,terms,cookies}.txt`. They are escaped and displayed on supporting noindex routes. Review [legal publication and operating procedures](docs/LEGAL-OPERATIONS.md), then set LEGAL_READY=true after all twelve documents are reviewed and SMTP/retention procedures are ready. Production registration refuses acceptance of unpublished terms; development signup remains available for testing. No legal or privacy guarantees beyond the supplied product claims are invented.

Optional CAPTCHA uses Turnstile: set CAPTCHA_ENABLED=true, CAPTCHA_SITE_KEY, CAPTCHA_SECRET and the provided verification endpoint. Keep it off without configured keys. Server-side CSRF, honeypot and rate limits operate independently.

See [deployment](docs/DEPLOYMENT.md) and [validation](docs/VALIDATION.md). Do not use PHP's development server for public traffic.
