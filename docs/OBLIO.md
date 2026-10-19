# Fiscal invoicing with Oblio

Configure Oblio authentication, issuer, VAT and issuance controls in /admin → API-uri Stripe și Oblio. After the first save, all OBLIO_* environment values are ignored. The environment variables below remain bootstrap fallbacks for installations that have not saved an admin configuration. See [configuration precedence and secret storage](API-SETTINGS.md).

Stripe remains responsible for card payments, subscription state, recurring charges and payment confirmation. Oblio is responsible for fiscal numbering and issued fiscal documents. A Stripe payment confirmation/receipt is not presented as the final fiscal invoice.

## Configuration

All defaults are safe for development:

```dotenv
OBLIO_ENABLED=false
OBLIO_EMAIL=
OBLIO_API_SECRET=
OBLIO_CIF=
OBLIO_SERIES=
OBLIO_WORKSTATION=
OBLIO_LANGUAGE=RO
OBLIO_VAT_RATE=
OBLIO_VAT_NAME=
OBLIO_ALLOW_TEST_PAYMENTS=false
```

OBLIO_COMPANY_CIF and OBLIO_SERIES_NAME are supported as legacy aliases. Set the issuer's actual CIF, numbering series, VAT rate and VAT nomenclature name according to its configured business treatment. No statutory VAT rate is inferred. The sample rates in automated fixtures are not a tax recommendation. Country inputs use two-letter country codes. Verify the issuer's nomenclatures and actual document layout with its accountant/Oblio account before live use.

The currency catalog accepts EUR for new monthly/yearly subscriptions and RON for historical payments. Amounts are integer minor units locally; the original payment currency is retained without application-level conversion. Verify issuer and exchange-rate settings in Oblio before real EUR issuance. Each plan carries its Romanian invoice name, unit, VAT configuration reference and product code in config/plans.php; yearly names/codes and full Stripe coverage periods identify annual services.

The app still accepts only Stripe TEST MODE. Setting OBLIO_ENABLED=true is a real fiscal side effect, not a sandbox switch. Real emission against test Stripe payments is additionally blocked unless OBLIO_ALLOW_TEST_PAYMENTS=true is deliberately configured. Nothing in this implementation run enabled it or issued a real document. Automated tests refuse production Oblio network transport and use local fixture clients.

## Install / upgrade

```sh
php bin/console.php migrate
```

The new idempotent migration adds billing_profiles, fiscal_invoices and the phase-2 fiscal_corrections relationship. It does not delete or replace existing authentication, subscription or invoice records. Run migrations before serving the updated application and before starting workers.

Existing paid users without a profile are directed to the authenticated localized billing-details route before starting a new checkout. Registration collects billing data for paid plans only. Customers can update their billing profile and view their fiscal history from their secure account/activation page. This does not add another normal public marketing page.

## Durable workflow

1. Verify the Stripe webhook signature/timestamp and reject live events in this release.
2. For invoice.paid, retrieve that exact invoice from Stripe, including all paginated lines. Never substitute the latest invoice, which may cover a later cycle.
3. Resolve the account, customer, subscription and configured plan. Update payment/entitlement state from the current subscription independently.
4. Persist a unique fiscal job for stripe_invoice_id in the same transaction that records the authoritative paid invoice.
5. Acknowledge the webhook once the billing state and durable work are saved. No Oblio network request runs inside the webhook.
6. The fiscal CLI worker freezes and encrypts the customer/plan/source snapshot and exact provider request before calling the provider.
7. Send idempotencyKey=zion-storage:{stripe_invoice_id}, keeping Oblio's automatic series numbering enabled.
8. Store the actual provider identifier when returned, series, number and validated document URL. A nullable provider ID is intentional: documented creation responses may return series/number/link without a separate ID.
9. Queue one localized invoice email. Only after SMTP accepts the email (or local test rendering succeeds) are notification_sent_at and completed_at set.

The source invoice records the actual amount_paid and currency. A single service line uses that effective collected gross amount with VAT included, so coupons/discounts cannot accidentally restore the undiscounted website price. Original discount/tax data remains in the encrypted source for audit. Mixed-period invoices, prorations, multiple lines, quantities other than one or unsupported currencies are retained as failed jobs for review instead of generating a guessed document. Zero collected amount is recorded as no_charge with no fictitious receipt.

Periods come from the subscription line's period.start/end, not the webhook receipt time or subscription's latest period. Exact exclusive-end timestamps remain stored. The Romanian description shows covered calendar dates in Europe/Bucharest, e.g. 16.10.2026 - 15.11.2026 for a cycle ending on 16 November.

## Run workers

Manual processing/retry (pending and failed only, maximum 20 jobs per invocation):

```sh
php bin/retry-oblio-invoices.php
# equivalent:
php bin/console.php oblio:retry
php bin/console.php mail:work
php bin/console.php fiscal-invoices
```

Run the following once per minute from the deployment scheduler:

```sh
php bin/console.php oblio:retry --due
php bin/console.php mail:work
```

For Docker, use `docker compose exec -T --user www-data web php bin/console.php oblio:retry --due` followed by the existing mail worker. The scheduler itself has not been installed on this development host.

Automatic retries respect backoff. An explicit manual retry ignores next_attempt_at but still skips issued, simulated, cancelled and no_charge documents. Interrupted processing is recovered only after acquiring its released process lock. Provider rate limiting respects 30 creation attempts per 100 seconds in this single-instance architecture. Commands return nonzero when processed jobs remain failed.

A provider outage never rolls back Stripe payment or turns a paid subscription into unpaid. Fiscal errors use safe codes in private logs/CLI, while the customer sees a neutral preparing state. If a payment succeeded but Oblio is unavailable, payment stays confirmed and the fiscal job remains retryable.

## Idempotency and recovery

Local UNIQUE(stripe_invoice_id), transaction boundaries, per-invoice locks, a frozen request and Oblio's documented idempotencyKey work together. After a lost response, a retry uses the same key and JSON request. Never clear request_snapshot, change a job's key, alter its issuer/series or manually create a second document just because a request timed out. If provider support is needed to reconcile an ambiguous existing document, use the recorded Stripe reference and key. Never guess an invoice number.

A billing profile is snapshotted when the paid event is accepted; later changes affect only future jobs. If a legacy profile was entirely absent, it can be supplied before the first valid issuance request. Issued snapshots are immutable through the application. Authentication data remains separate from billing data.

Simulation/live intent is also frozen in the source. With OBLIO_ENABLED=false, valid jobs become simulated, receive only a local simulation reference, retain no real provider ID and send no misleading invoice-issued email. They cannot be silently promoted when the configuration is later enabled. Disabling the provider while a real-mode job is pending leaves it failed/pending; it does not turn that fiscal obligation into a simulation.

Back up APP_KEY along with the database. It encrypts both queued emails and persistent fiscal source/request snapshots. Do not rotate it without a deliberate re-encryption migration; draining the mail queue alone is no longer sufficient.

## Document/email data and security

The provider request uses the local billing profile, not Stripe's optional customer address. It supports individuals and companies/PFA, including contact name, email, address, postal code and optional registration/phone fields. Client autocomplete and saving are disabled to avoid replacing the local snapshot with provider-side customer data. The documented clientLocation contains the complete address including postal code.

Invoice emails exist in RO/EN/FR/DE and contain plan/capacity, paid amount/currency, covered period, series/number, document link when provided and next renewal. They go to the local billing email in the account locale. Provider-generated emails are disabled. The returned link may open a document preview/PDF depending on provider settings; no PDF attachment is fabricated.

Provider API credentials, access tokens, passwords and raw provider error bodies are never logged or returned to the browser. HTTP is HTTPS-only to the fixed official API host, with redirects disabled. Document links accept only HTTPS oblio.eu/www.oblio.eu URLs without userinfo or explicit ports. Fiscal snapshots/outbox are private and authenticated-encrypted at rest.

## Cancellation, failure and phase 2

- Free plans never enqueue a fiscal job.
- invoice.payment_failed never creates a fiscal document.
- Subscription cancellation never creates or cancels a document and never deletes an original invoice.
- Refund/storno automation is explicitly phase 2. fiscal_corrections links future corrective documents to the original and a unique Stripe refund ID. There is no automatic refund webhook handler or automatic destructive cancellation in this release.
- OblioClient has separate getInvoice/cancelInvoice capabilities; billing never calls cancellation. A returned document URL is optional.

## Sources and external acceptance

Official API contracts: [Oblio API](https://www.oblio.eu/api/), [official WooCommerce integration](https://github.com/OblioSoftware/oblio-woocommerce), [Stripe invoice retrieval](https://docs.stripe.com/api/invoices/retrieve), [Stripe line item periods](https://docs.stripe.com/api/invoice-line-item/object).

Local acceptance exercises initial purchase, duplicate events, renewals, outage, safe retry after remote creation/response loss, interrupted workers, profile changes, amounts/periods, tax configuration, email completion, failed payments, cancellation and simulation. Complete a controlled provider-account acceptance with approved credentials/issuer settings before enabling real fiscal issuance. No actual Oblio account, production numbering sequence or fiscal registration was changed.

## Automatic processing and recovery

The updated Docker `mail-worker` processes due fiscal jobs before delivering email. It keeps retry backoff, frozen simulation intent, TEST-payment permission and invoice idempotency intact. The Stripe webhook only persists the job and never waits for Oblio network requests.

For the custom Synology layout with only `zion_storage_web`, schedule this as root every minute (no new container, volumes or environment mapping required):

```sh
docker exec --user www-data zion_storage_web php /var/www/html/bin/console.php operations:work
```

This processes due fiscal documents and then mail, including newly issued invoice notifications. `mail:work` remains mail-only. `oblio:retry --due` remains fiscal-only.

Admin → Facturi fiscale shows queue counts, the real worker heartbeat and human-readable error reasons. Each pending/failed row has a CSRF-protected issuance retry. Issued and simulated rows cannot be retried into new invoices. If no row exists for a paid order, inspect/replay its original `invoice.paid` event through the Stripe API journal. A successful API connection test alone does not create a fiscal job. A Stripe TEST transaction remains blocked from real issuance unless the existing explicit setting permits it; this update does not turn that setting on or convert old simulations.
