# Stripe and Oblio configuration in administration

Open /admin and expand **API-uri Stripe și Oblio**. Each service has an independent save form:

- Stripe: secret API key and webhook signing secret. This release supports TEST MODE keys only. Product/Price IDs remain in the adjacent billing catalog. The form shows the webhook URL and required events.
- Oblio: account email, API secret, issuer CIF, invoice series, optional workstation, document language, VAT rate/name, real issuance toggle and explicit permission to issue real documents for Stripe test payments.

Saving validates formats and required fields locally. It does not contact Stripe/Oblio, create a payment, issue an invoice or confirm that the credentials have provider access. Use the existing TEST Checkout workflow and controlled fiscal acceptance procedure to verify the external accounts.

## Source precedence

Before a service is first saved, its existing .env settings remain the fallback. After the first save, its whole configuration comes exclusively from the database. Empty values and disabled toggles are authoritative: they never reactivate an environment value. Saving Stripe does not change the source for Oblio, and vice versa. There is no automatic reset to environment settings.

On the first save, enter the credentials you want to keep using; secrets from .env are never copied into form inputs. On later saves, a blank secret field retains the previously saved database value. Use the explicit clear checkbox to remove a secret. An enabled Oblio configuration cannot be saved without the required authentication, issuer, series and VAT fields.

Web requests, catalog actions, Checkout, upgrades, webhook signature verification, portal configuration and CLI workers all resolve this shared configuration. Each request/worker invocation takes its configuration when its services are instantiated; a running batch finishes with that configuration, and the next invocation uses the saved update. New fiscal snapshots obtain VAT from the same source. Historical invoice/payment snapshots remain immutable.

## Storage and access

Provider records are authenticated-encrypted with APP_KEY in integration_settings. Back up both the database and APP_KEY; preserve the key when deploying or restoring. APP_KEY, bootstrap admin credentials, application URL, database and SMTP settings remain server environment configuration.

Only an authenticated administrator with valid CSRF can save settings. Successful changes are atomic with a credential-free audit entry. Secrets are never rendered back into HTML, copied into errors or included in audit details. API URLs are fixed by the application; the settings do not accept arbitrary endpoints.

Changes in Stripe account or issuer should be coordinated with the existing subscriptions, Prices and fiscal snapshots. Saving credentials does not migrate those records or create new financial operations.

Validation: composer test; composer lint; python3 tests/http_flow.py; python3 tests/admin_http.py --browser (Playwright/Chrome required). Fixtures exercise conflicting environment values, API authentication, webhook signatures, secret retention/removal, fiscal VAT/simulation, invalid updates, encrypted persistence, CSRF and responsive forms without external service calls.
