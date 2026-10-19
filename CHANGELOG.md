# Changelog

## 2026-10-19

- Added deduplicated, encrypted administrator notifications for new registrations and immediate Listmonk dispatch. Enabled due Oblio invoice processing in the Docker mail worker and added a combined operations:work command for existing Synology containers, fiscal worker activity, readable issuance errors and protected per-invoice retries.

- Updated transactional emails with a branded charcoal layout, lime confirmation button and a full selectable fallback URL below it, with localized instructions in RO/EN/FR/DE and unchanged plaintext links.

- Clarified verification-to-payment onboarding in all four languages: browser confirmation uses a CSRF-protected POST automatically, followed by an explicit account-confirmed screen with plan, billing interval and a payment button showing the gross EUR total. Opening the email link via GET alone still does not consume the token or start a payment.

- Fixed signup/resend Listmonk confirmations waiting indefinitely for a mail worker: send the current confirmation immediately after account persistence, retain failed deliveries for retry and discard invalidated/expired links. Added CLI worker activity, queue diagnostics and a protected manual queue action to the Listmonk admin page.

- Applied the self-hosted Unbounded heading font across all public pages, including the homepage, carousel and pricing. Adjusted heading sizes for responsive layouts and versioned the stylesheet URLs; body text and admin typography stay unchanged.

- Added encrypted Listmonk transactional email configuration in admin, saved token previews, template/connection checks, explicit test emails, redacted API diagnostics and a Docker mail worker. Saved settings override LISTMONK environment values; existing localized outbox messages remain pending on upstream errors.

- Added operator-specific terms, privacy and cookie pages in RO/EN/FR/DE, printable structured legal content, document-preserving language links, explicit adult declaration and acceptance versions. Added a public withdrawal declaration/receipt with encrypted records and administrative handling, while preserving mandatory consumer remedies. Updated contact/sender address and the unsaved VAT default for the declared non-VAT-registered PFA; saved tax profiles and Stripe subscriptions are not silently rewritten.

- Added encrypted Immich configuration, authenticated connection testing, manual read-only statistics sync, a five-minute Docker worker and CLI cron command, per-client photo/video and usage snapshots, dashboard totals and top-ten usage-versus-plan ranking. Saved configuration overrides Immich environment settings across provisioning and the access gateway; failures retain and label prior snapshots.

## 2026-10-18

- Added global exclusive VAT configuration in admin (Romania standard 21% by default), automatically managed Stripe TEST/LIVE Tax Rates, separate Checkout tax lines for monthly/annual subscriptions and one-time upgrades, localized gross totals and immutable fiscal VAT snapshots. Existing subscriptions and open quotes retain their original tax policy.

- Added automatic storage activation after verified payment, with a temporary encrypted onboarding credential, automatic status updates, serialized retries and credential removal on completion or after 24 hours. Existing accounts can submit their password once to enter the retry flow.

- Added confirmed TEST-account resets that remove local billing records, expire open Checkout sessions, delete the owned Stripe test customer and optionally purge its verified Immich account. Remote cleanup is resumable, LIVE/real-fiscal histories are protected, and delayed callbacks cannot recreate deleted records.
- Added webhook receipt/processing stages, signature diagnostics, event/delivery correlation and authenticated Stripe event recovery to the admin API journal, preserving payment and fiscal idempotency.
- Added audited activation-link disclosure for new unconfirmed customers in admin, reusing valid links or issuing a fresh one-hour link when needed so onboarding works before SMTP is configured.
- Added an admin API communication journal with filtered/paginated Stripe, Oblio, Immich, CAPTCHA and webhook records, redacted diagnostics, connection errors, HTTP status, timing and Stripe request IDs. Logs survive billing rollbacks and retain up to 30 days/10,000 entries.
- Changed admin API fields to text inputs containing the saved masked preview, preserving complete credentials when unchanged previews are submitted.
- Added partial previews of saved Stripe TEST/LIVE and Oblio API secrets in admin, with field-specific setup instructions and official links.
- Added a localized two-scene home carousel combining the mobile backup animation with the illustrative Google Photos/shared-storage versus Zion dedicated-media comparison. Includes autoplay, pause, keyboard/swipe navigation, reduced-motion support and a fixed layout across scene changes.
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
