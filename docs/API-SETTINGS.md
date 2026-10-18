# Stripe and Oblio configuration in administration

Open /admin → Configuration → API-uri Stripe și Oblio. Credentials remain authenticated-encrypted with APP_KEY. Once a provider has been saved, its environment values are ignored, including when a saved value is empty or disabled.

## Stripe TEST and LIVE

Two independent profiles store the secret API key and webhook signing secret. Old single-profile installations migrate logically to TEST, and existing local billing records migrate to TEST without changing their financial history. LIVE starts empty. On the first save, enter the keys explicitly; on later saves, blank fields retain that profile's saved secrets, and the clear checkbox removes them. Full stored secrets are never returned to HTML. Saved credentials appear as actual values in text inputs with their first seven and last six characters separated by a fixed mask; credentials of 13 characters or fewer are fully masked. Resubmitting an unchanged mask preserves the encrypted credential; edited masks are rejected. To replace a credential, select the entire value and enter the complete new key. Each field includes official setup links and guidance for obtaining the API key, webhook signing secret or Oblio client_secret.

1. Select TEST or LIVE and enter the matching secret API key and webhook signing secret.
2. Save. The application authenticates against Stripe and checks Products/Prices read access, then automatically synchronizes the six paid options. No Product/Price IDs are required.
3. Check the three separate panels: API connection, catalog synchronization and signed webhook receipt. They show timestamps and are tied to that profile's credentials.
4. Use Activate TEST/LIVE to change the public mode. Activation rechecks the API and all six prices, requires both secrets, and records the change in the audit log. Saving a profile alone never activates it.

Automatic synchronization is triggered by saving a nonempty Stripe API key. The single Sync catalog button reruns the whole catalog when desired; it is not a scheduled background poll. It creates one Product per paid plan and monthly/yearly recurring EUR Prices, or reuses existing compatible mappings/lookup keys. Stable Product IDs, Price lookup keys, mode-specific idempotency keys and a lock make interrupted work resumable without duplicates. Confirmed partial results remain available for retry, and disabled options remain disabled. Historical mappings and existing subscriptions are not repriced.

If the API key or canonical catalog changes, previous diagnostic results no longer count for activation. Raw provider exceptions are never shown or logged. APP_ENV=test forbids external diagnostics unless a fixture client is explicitly injected by the test suite.

## Webhooks and payment validation

Register separate Stripe webhook endpoints shown by the form:

- /webhooks/stripe?mode=test
- /webhooks/stripe?mode=live

Subscribe to checkout.session.completed, invoice.paid, invoice.payment_failed, customer.subscription.created, customer.subscription.updated and customer.subscription.deleted. On localhost, use Stripe CLI forwarding for TEST and save its signing secret in the TEST profile. LIVE requires a reachable public HTTPS endpoint.

A successful API read does not verify a signing secret or Checkout permissions. The webhook panel turns verified only after the server actually receives an event with a valid signature for the corresponding mode and credentials. Receipt alone does not prove payment processing/activation; complete a TEST Checkout and inspect the existing payment/event records. The API test makes no payment and issues no fiscal invoice.

The API journal now has Webhookuri Stripe and Webhookuri cu erori shortcuts. Each incoming delivery records a start (202, diagnostic only) and final handling status, linked by a generated delivery ID. The actual HTTP response remains 204 on accepted events, 400 on invalid payload/signature/mode, and 503 on configuration/processing errors. Logs include allowlisted event/resource IDs, event type/API version, expected/payload mode, signature outcome, processing stage, final outcome and the locally confirmed active-payment flag where evaluated. Stages identify decoding, mode/secret/signature checks, invoice/subscription retrieval, user mapping, plan validation, persistence, storage synchronization and notifications. Ignored, duplicate and subscription-less events are explicitly distinguished from applying a payment. A receipt without a final result helps identify interrupted handlers. SDK calls during handling share event/delivery context. Use the Event ID / ID livrare filter to correlate records; raw payloads and signature headers are never recorded.

For an already paid subscription stuck waiting, first configure the correct TEST/sandbox or LIVE webhook destination and its own signing secret. Saving a secret in this application does not create a Stripe webhook endpoint. Ensure the public URL uses HTTPS without relying on redirects, and subscribe to the six listed events. Then resend the original event from Stripe, or open the journal's debug guide and use Verifică și reprocesează evenimentul with its original Event ID and mode. Prefer invoice.paid to recover local payment/fiscal records together. Recovery retrieves the event from the selected Stripe account over the authenticated API, checks its identity/mode/type, and reuses the normal authoritative subscription/invoice processing with its original event ID. It creates no new payment; duplicate completed events and fiscal jobs remain idempotent. It does not mark webhook signing-secret health as verified. External recovery is disabled in isolated tests; unit fixtures explicitly override retrieval. Stripe only guarantees event retrieval for 30 days: [Retrieve an event](https://docs.stripe.com/api/events/retrieve).

The customer's Continue link only reloads local state. It deliberately does not grant access from a Checkout redirect or charge the customer again. After authoritative payment recovery, the normal password/terms/storage activation step still applies.

Each registration, Checkout, subscription, plan change, fiscal invoice and recorded receipt retains its mode. Existing TEST accounts stay TEST after public activation of LIVE. Existing LIVE subscription webhooks, upgrades, portal operations and retries continue using LIVE when the public site switches to TEST. Changing the public mode never migrates a customer between payment environments.

Prices, Product associations, diagnostics and manual pauses are isolated by mode. Dashboard financial/subscription metrics use the active mode; local user/access/quota totals remain shared. The site header shows a compact sandbox payment notice in RO/EN/FR/DE whenever TEST is active, and hides it in LIVE.

## API communication journal

Open /admin → Jurnal administrare → Comunicare API. The separate Acțiuni administrare submenu retains the existing operator audit trail. The menu remains at two vertical levels. Filter communication records by provider, success/error, Stripe TEST/LIVE and incoming/outgoing direction, with 50 rows per page.

Logging is automatic for all application Stripe SDK requests, not just connection tests: catalog, Checkout, subscriptions, portal operations, payment/upgrade verification and workers use the same instrumented transport. Oblio authentication and document requests, Immich provisioning/quota/gateway identity calls, CAPTCHA verification and incoming Stripe webhooks are also covered. SMTP delivery and the Immich media traffic forwarded directly by Nginx are not API calls made by this PHP application and are not included. Fixture transports that replace the SDK client are outside this instrumentation.

Records contain time, service, mode, direction, method, sanitized host/route, HTTP status (or no response), duration, provider/transport error code, filtered diagnostic message and Stripe Request ID when returned. Stripe SDK internal retries appear as one completed transport operation. No request/response bodies, authentication headers, cookies or query parameters are stored; error-message text is scrubbed for request values, credentials, URLs, emails and long numbers. No stack traces or webhook payloads are captured. Admin pages escape all diagnostic text and use no-store responses. Webhook signature failures use a fixed safe message. A successful webhook entry describes request handling, not necessarily activation or collection of a payment.

The journal is a separate `api-communication.sqlite` file beside DB_PATH, outside the public directory. This preserves transport diagnostics when billing transactions roll back and avoids contention with the business database. API_LOG_PATH can optionally override this location. Keep its directory writable by the same PHP/worker user; files are created with mode 0600. Writes retain at most 10,000 entries from the last 30 days; cleanup runs with each new record. Logging failures never change the API result and emit only a generic server-log warning. The admin page reports an unreadable log file explicitly.

For “Stripe nu poate fi contactat”, repeat Testează conexiunea Stripe after installing this version, then follow the error-journal link beside the Stripe settings. DNS failures, timeout/connectivity failures and missing CA/TLS certificate errors retain the underlying transport details. HTTP failures show provider codes/messages and Request IDs for correlation with Stripe. The journal adds diagnostics without disabling TLS verification or changing retry/payment behavior. It cannot recover errors that happened before installation.

## Oblio and deployment

Oblio still has its own independent configuration: account email/API secret, issuer CIF, invoice series, optional workstation, document language, VAT rate/name, real issuance toggle and permission to issue real documents for Stripe TEST payments. Saving Oblio validates locally and does not contact it. LIVE Stripe payments use the same issuer settings; simulation remains the default until real issuance is explicitly enabled.

Run installation/migration before serving this version. Back up the database and APP_KEY, including stripe_catalog_products and mode-scoped billing_prices. APP_KEY, bootstrap admin credentials, URL, storage and SMTP configuration remain server settings. No private key belongs in source control.

Validation covers both mode profiles, automatic synchronization, lost-response/partial-failure recovery, no duplicates, pauses, wrong-mode signatures, LIVE payment/upgrade recovery under public TEST, portal/admin plan changes, financial isolation, HTTP activation guards, masked secrets, localized sandbox notices and responsive forms. Automated checks use isolated transports and never contact Stripe or Oblio.

References: [Stripe keys and environments](https://docs.stripe.com/keys), [Product creation](https://docs.stripe.com/api/products/create), [Price creation and lookup keys](https://docs.stripe.com/api/prices/create), [Webhook signature troubleshooting](https://docs.stripe.com/events/manage-webhook-endpoints).
