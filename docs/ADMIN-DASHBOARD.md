# Administration dashboard and navigation

/admin opens the private dashboard after authentication. The vertical sidebar has at most two levels: Dashboard; Management with Clients, New client, Subscriptions, Upgrades, Fiscal invoices and Operations; Configuration with APIs and Stripe catalog; Audit log. On mobile the menu opens with a native CSS checkbox control. Navigation, forms and search work without JavaScript; the existing script-free admin CSP remains in place.

Each dedicated view uses an allowlisted section query parameter, for example /admin?section=clients. Unknown sections return 404. Saving a form returns to its section. Client search preserves the section and active attention filter. Authentication, separate admin cookie, CSRF, audit and payment/provisioning rules are unchanged.

## Dashboard metrics

- Registered clients: all local users, with registrations during the past thirty days.
- Active access: locally active users excluding suspended accounts. Unverified and suspended totals are separate.
- Active subscriptions: active Stripe subscriptions with a confirmed latest payment, split monthly/yearly. Suspension does not cancel a subscription, so this metric remains independent of active access.
- Attention: the sum of incomplete/reviewable upgrade operations, fiscal jobs, failed email attempts, unfinished webhook events, account synchronization problems and subscription payment problems. This is a count of conditions, not unique clients.
- Recorded receipts: actual positive local Stripe invoice amounts and confirmed one-time upgrades from the last thirty days through the current time, grouped by original currency and deduplicated by invoice ID. These are gross TEST payments; refunds are not subtracted, and EUR/RON are never combined or converted.
- Plan distribution and configured allocated quota: local plan assignments and stored quota bytes. Quota is not used disk space or the server's physical free capacity.
- Upcoming renewals: active paid subscriptions renewing within seven days; recent registrations and local integration configuration status.

The dashboard makes no provider API calls. Configured integration status does not verify credentials or network connectivity. All dates display Europe/Bucharest. Refresh the page to update the snapshot. Metrics cover all local records, independently of the result limits in management tables.

## Dedicated management views

Clients, subscriptions, upgrades and fiscal invoices have All / Needs review filters. Dashboard priority links select the corresponding attention filter. Lists show at most one hundred records; Operations shows the oldest thirty queued emails and unfinished webhooks, without private message payloads. Financial and quota retries continue to use the existing workflows/workers. No financial actions are triggered by viewing the dashboard.

Validation: composer test (empty/seeded metric semantics, currency separation, time bounds, suspension and synchronization), composer lint, and python3 tests/admin_http.py --browser (Playwright/Chrome). Browser checks cover all ten views at 320/390/768/1440 pixels, two-level vertical navigation, section isolation, masked secrets, subscription filters, no-JavaScript search and existing paid-upgrade flows.
