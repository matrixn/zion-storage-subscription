# Changelog

## 2026-10-18

- Replaced the layer-stack icon with a generated lime photo-stack emblem across site branding, mobile touch/app icons, browser favicons and social sharing artwork. Preserved a high-resolution brand master and its generation brief.
- Redesigned the existing home pricing section with localized inline prices, specific plan benefits, a prominent Personal ribbon and an accessible illustrative storage comparison. Added single-column mobile, two-column tablet and four-column desktop layouts with reduced-motion support.
- Changed the Plus catalog price to 17.99 RON/month. Its Stripe Price ID must be configured manually for 1799 RON minor units; no existing subscriptions or live Stripe prices are modified.
- Added optional legacy Stripe Price recognition so previous Plus subscription payments and invoice descriptions remain valid without using an old Price for new Checkout.
- Added one-time paid upgrades during the original five-day purchase window, with exact RON price differences, preserved renewal dates, authoritative Stripe payment checks, durable recovery and separate upgrade fiscal records.
- Added private `/admin` authentication, install-time ADMIN_EMAIL/ADMIN_PASSWORD bootstrap, customer creation/manual plans, recurring plan changes, ban/unban, upgrade recovery and operator audit history.
- Added a non-destructive Immich access gateway/proxy example and explicit pending states when full ban enforcement is not configured; billing cannot clear bans.
- Restricted the Stripe billing portal so it cannot bypass the upgrade policy.
- Updated monthly prices to 9.99 RON for 120 GB, 19.99 RON for 250 GB and 29.99 RON for 500 GB.
- Removed the 1 TB offer and adjusted the pricing layout for the four remaining plans, including the free 10 GB plan.

## 2026-10-17

- Added separate individual/company billing profiles to paid registration and authenticated account flows in RO/EN/FR/DE.
- Added durable Oblio fiscal invoice jobs keyed by Stripe invoice, provider-side idempotency, encrypted immutable snapshots and retry/inspection CLI tools.
- Added configurable fiscal metadata/VAT, exact covered periods and actual collected Stripe amounts.
- Added localized fiscal invoice notifications and private document history; Stripe payment receipts remain distinct from fiscal invoices.
- Added safe default-off simulation and independent payment/fiscal completion states.
- Prepared linked corrective-document records; automatic refunds/storno remain phase 2.
