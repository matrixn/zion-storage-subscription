# Stripe and Oblio configuration in administration

Open /admin → Configuration → API-uri Stripe și Oblio. Credentials remain authenticated-encrypted with APP_KEY. Once a provider has been saved, its environment values are ignored, including when a saved value is empty or disabled.

## Stripe TEST and LIVE

Two independent profiles store the secret API key and webhook signing secret. Old single-profile installations migrate logically to TEST, and existing local billing records migrate to TEST without changing their financial history. LIVE starts empty. On the first save, enter the keys explicitly; on later saves, blank fields retain that profile's saved secrets, and the clear checkbox removes them. Secrets are never returned to HTML.

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

Each registration, Checkout, subscription, plan change, fiscal invoice and recorded receipt retains its mode. Existing TEST accounts stay TEST after public activation of LIVE. Existing LIVE subscription webhooks, upgrades, portal operations and retries continue using LIVE when the public site switches to TEST. Changing the public mode never migrates a customer between payment environments.

Prices, Product associations, diagnostics and manual pauses are isolated by mode. Dashboard financial/subscription metrics use the active mode; local user/access/quota totals remain shared. The site header shows a compact sandbox payment notice in RO/EN/FR/DE whenever TEST is active, and hides it in LIVE.

## Oblio and deployment

Oblio still has its own independent configuration: account email/API secret, issuer CIF, invoice series, optional workstation, document language, VAT rate/name, real issuance toggle and permission to issue real documents for Stripe TEST payments. Saving Oblio validates locally and does not contact it. LIVE Stripe payments use the same issuer settings; simulation remains the default until real issuance is explicitly enabled.

Run installation/migration before serving this version. Back up the database and APP_KEY, including stripe_catalog_products and mode-scoped billing_prices. APP_KEY, bootstrap admin credentials, URL, storage and SMTP configuration remain server settings. No private key belongs in source control.

Validation covers both mode profiles, automatic synchronization, lost-response/partial-failure recovery, no duplicates, pauses, wrong-mode signatures, LIVE payment/upgrade recovery under public TEST, portal/admin plan changes, financial isolation, HTTP activation guards, masked secrets, localized sandbox notices and responsive forms. Automated checks use isolated transports and never contact Stripe or Oblio.

References: [Stripe keys and environments](https://docs.stripe.com/keys), [Product creation](https://docs.stripe.com/api/products/create), [Price creation and lookup keys](https://docs.stripe.com/api/prices/create), [Webhook signature troubleshooting](https://docs.stripe.com/events/manage-webhook-endpoints).
