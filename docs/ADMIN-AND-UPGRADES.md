# Administration and paid upgrades

## Install

Set private `.env` values `ADMIN_EMAIL` and `ADMIN_PASSWORD` (12–200 bytes, at least 12 characters), then run:

```sh
php bin/console.php install
# Container equivalent:
docker compose run --rm --user www-data web php bin/console.php install
```

`migrate` also performs this initial administrator bootstrap. Only the Argon2id hash is stored, in a separate administrators table. There is no default password or public administrator registration. Repeated installation preserves existing passwords; editing the environment does not silently reset an existing administrator. Keep the environment private and limit administrative network access at the proxy as appropriate.

Open `/admin`. Its one-hour session uses a separate HttpOnly, SameSite cookie scoped to `/admin`, CSRF protection, login throttling, no-store responses and noindex. Customer email-link sessions do not confer administrative privileges. All state changes require POST. Operator actions are recorded with administrator, customer, timestamp and reason.

The dashboard provides customer search, manual customer creation, plan changes, ban/unban, pending quota synchronization, upgrade/payment history, cancellation of unpaid Checkout sessions, and retries of confirmed changes. Financial retries verify the original payment and do not take another payment.

New manual customers receive an email confirmation. Give their initial password through an appropriate secure channel; it is never emailed or stored as plaintext. They must confirm their email and personally accept terms/privacy before activation. A manual plan is an operator entitlement, not a paid subscription: it creates neither a Stripe payment nor a fiscal invoice. To start recurring collection for a manually managed customer, use a separately agreed billing migration; granting a manual plan does not imply consent to recurring charges.

For existing active paid subscriptions, administrative upgrades/downgrades update the recurring Stripe price at the existing renewal date. They neither collect the customer upgrade difference nor issue an immediate refund. This is stated on the form and in the audit trail. A downgrade is refused if existing files exceed the target quota. Switching a recurring subscription to Free requires explicitly cancelling the Stripe subscription first; the plan selector never silently cancels payments. Pending financial changes must be resolved before another plan change. An open initial subscription Checkout also blocks a manual grant until confirmed/expired.

## Customer upgrade policy

- Paid monthly plans only, moving to strictly larger capacity and higher price. Free/manual accounts and banned accounts do not qualify.
- Window: five times 24 hours from the **original successful purchase**, using Stripe's paid timestamp. Receipt of a webhook, a renewal, an upgrade or an administrative change never starts a new window.
- Full difference in monthly catalog prices, once, in RON integer minor units. Example: 120 GB 999 → 250 GB 1999 means a separate 1000-minor-unit (10 RON) payment now, then 1999 monthly at the original renewal date.
- Checkout uses `mode=payment`, the existing Stripe customer, card payments, a separate post-purchase invoice and durable operation metadata. The browser cannot supply an amount or activate the plan.
- A signed webhook retrieves the exact saved Checkout session, expanded PaymentIntent/charge and invoice. Customer, metadata, test mode, currency, collected amount, successful payment and the five-day deadline are verified before applying the change.
- The existing subscription item is updated using `proration_behavior=none`, `billing_cycle_anchor=unchanged` and an operation-specific idempotency key. No second subscription is created. Monthly renewal follows Stripe's existing calendar anchor, including February and short months.
- Current and target Stripe Prices must match the catalog and be monthly RON prices. Existing grandfathered prices, schedules or pending Stripe updates need explicit operator migration; they are not silently repriced.
- The generated billing portal configuration permits payment-method management, invoice history and cancellation at period end, with subscription updates disabled. A changed/unsafe portal configuration is rejected so the portal cannot bypass the five-day policy.

**Checkout deadline:** Stripe requires each new Checkout session to have at least 30 minutes of validity. New upgrade sessions stop opening 30 minutes before the five-day deadline; existing sessions expire no later than that deadline. Both dates/conditions are disclosed before payment. Payment outside the deadline is rejected for entitlement changes and remains visible for operator review; refunds/corrective fiscal documents require the existing manual phase-2 procedure. Never tell a customer to pay a pending/confirmed operation again.

Only an authoritative `subscription_create` paid invoice opens the window. Historical accounts lacking this record are not assumed to have bought recently. Replaying their original signed `invoice.paid` event can populate the record without changing the purchase timestamp; old purchases remain ineligible.

Fiscal jobs for the one-time upgrade use the **actual difference collected**, a distinct Romanian upgrade line, and the remaining original billing period. They retain the same provider idempotency, encrypted snapshots, simulation, worker and notification rules as normal invoices. A monthly renewal uses the new monthly plan and its own Stripe/fiscal invoice.

Sources: [Stripe subscription updates](https://docs.stripe.com/api/subscriptions/update), [Checkout session creation](https://docs.stripe.com/api/checkout/sessions/create), [Checkout fulfillment](https://docs.stripe.com/checkout/fulfillment).

## Suspend without deleting files

Ban immediately prevents Zion activation and upgrades. Billing webhooks check the separate ban flag and cannot clear it. The subscription and files are retained. Unban restores the current eligible quota; an unpaid/inactive account remains restricted.

Immich's documented user update API does not have a general account-disabled flag. Deleting the user schedules file deletion and is **never** used for ban. Quota restriction by itself blocks new uploads but does not revoke downloads. Full access suspension therefore requires the supplied private access gateway:

1. Generate a separate random `STORAGE_GATEWAY_KEY` of at least 32 characters. Configure the same value in the private Nginx auth subrequest; never expose it to clients or logs.
2. Install [the Nginx auth_request example](immich-gateway.nginx.conf.example) on the public Immich proxy, replacing upstreams and the secret. Replace existing API locations rather than leaving competing or bypass locations. Nginx must include the auth_request module.
3. Block public/direct access to the Immich upstream port. All API traffic, including app requests, download/thumbnail routes and WebSocket handshakes, must traverse this proxy. Use HTTPS and private connectivity between services.
4. The gateway verifies the incoming customer's bearer/cookie/API-key identity through Immich `/users/me`; it never substitutes the administrator API key for user authentication. Known storage IDs map to Zion users. Safe-label reconciliation also recognizes accounts whose creation response was lost before the local storage ID was saved.
5. Only discovery/login endpoints are anonymous. Anonymous shared-link API access is intentionally unavailable with this configuration. Validate installed Immich/mobile versions, OAuth bootstrap, restricted API keys and socket authentication before deployment; add no blanket exemptions for asset/download/share endpoints. In-flight connections/local copies are not remotely erased.
6. Test an existing mobile/browser session: normal reads succeed; ban produces 403 for subsequent asset/API requests; unban restores access; disconnecting the identity check fails closed; a direct upstream request is unreachable. Test deletion was never called.
7. Only after these checks, set `STORAGE_GATEWAY_ENFORCED=true`. Reapply any pending bans from admin to refresh their synchronization status. Without the configured gateway, admin displays a warning and `gateway_required`; it does not claim that quota restriction provides full access suspension.

The gateway endpoint `/internal/storage-access` requires the shared key and returns only 204/403, with no customer data. Do not use it as a public general authentication service. Monitor its latency/availability: a failed identity lookup denies protected requests.

Source: [Immich user management](https://docs.immich.app/administration/user-management/), [official generated OpenAPI contract](https://github.com/immich-app/immich/blob/main/open-api/immich-openapi-specs.json). Verify against the installed version.

## Recovery and workers

Schedule `php bin/console.php changes:retry` every minute, before `oblio:retry --due` and `mail:work`, using the same environment and service user. This retries interrupted Stripe/entitlement operations, reconciles expired sessions and retries manual quota synchronization. Admin also exposes retry/cancel controls. Ban quota failures remain visible and can be retried through the suspend action.

Operations are serialized in the same billing → per-user lock order as webhooks. A remote price change whose response was lost is reconciled by its authoritative subscription webhook. Successful payment remains durable if quota or fiscal work fails. Unpaid-session cancellation first expires the Stripe session; an already completed payment cannot be marked cancelled locally. External cancellation, refunds or unexpected subscription mutations require operator review rather than an automatic second charge.

No live Stripe, real customer, installed Immich proxy or real fiscal provider was modified by local development. The release retains the existing Stripe TEST MODE restriction.
