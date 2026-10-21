# Customer details, fiscal documents and activation guide

Open **Admin → Clienți**, then click the customer name. The detail page contains
contact information, language, billing profile, subscription and storage statistics,
all fiscal documents and the personal onboarding URL.

Contact edits synchronize the linked Immich user and Stripe customer, preserving
the subscription and issued invoices. A durable encrypted intent survives partial
provider failures. Use **Reîncearcă sincronizarea datelor** to finish that same edit.
An administrator's explicit email correction revokes existing portal sessions,
email-verification links and onboarding links. Generate a new guide link afterwards.
Billing-profile edits apply to future documents; historic invoice snapshots remain intact.

## Fiscal PDF and email

The fiscal page offers download of a selected document or the customer's latest
issued document. Downloads require an authenticated administrator and verify local
invoice ownership. The document is looked up again in Oblio using its original
issuer/series/number. Only HTTPS Oblio PDF URLs are fetched, with no redirects,
no bearer credentials on the file request, a 10 MiB limit and a PDF signature check.
Simulations and unissued jobs do not expose a PDF action.

**Trimite factura prin email** queues a copy, fetches the PDF and attaches it to a
localized introduction in RO/EN/FR/DE. The recipient is the billing email, falling
back to the account email. Listmonk receives multipart `data` and `file` parts;
SMTP receives a MIME attachment. No invoice is issued or modified by either action.
The admin notice distinguishes provider acceptance, pending failure and local
simulation. Provider acceptance does not establish inbox delivery. Failed jobs remain
in the existing outbox and can be retried through the email settings/worker.

## Three-day guide access

After the first authoritative positive Stripe invoice is persisted, a 72-hour link
is created and a localized onboarding email queued. Replayed events and renewals
do not rotate it. Existing customers are not emailed in bulk during migration.
The existing device-aware guide is used. Pending account provisioning is still shown
truthfully and existing activation retries remain available.

The random token is stored hashed for lookup and encrypted for administrator copy.
It travels in the URL fragment, then is exchanged by CSRF-protected POST for a
restricted guide session. Email-scanner GETs do not activate accounts. The session
cannot access checkout, billing details, the Stripe portal or administrative actions.
Each access checks expiry, entitlement, verification and suspension again.

The administrator can copy the active link or regenerate it. Regeneration revokes
the previous link and associated guide sessions, removes stale unsent emails and
attempts immediate dispatch of the new message. The notice reports the result.

## Free quota

The central free plan is **5 GB** (`5_000_000_000` bytes). Existing linked free
accounts are queued once for quota reconciliation during migration, preserving
suspension and access settings. Apply only those pending free-account limits with:

```sh
php bin/console.php free-quota:sync
```

This adjusts quotas without deleting files or changing paid plans. Failed provider
updates remain pending and are visible in the existing client quota diagnostics.

## Validation

```sh
composer lint
composer test
python3 tests/http_flow.py
python3 tests/admin_http.py
python3 tests/listmonk-multipart.py
PLAYWRIGHT_MODULE=/path/to/playwright node tests/customer-documents-browser.cjs
```

Provider tests use fixtures and do not send real customer email or issue real invoices.
The browser test captures desktop and mobile previews under ignored `tests/artifacts/`.

## Deployment and rollback

Before deployment, keep the current image ID, Git commit and a private SQLite backup
using SQLite's backup API. Run the existing NAS `update.sh`, then the targeted free
quota sync and a read-only HTTP smoke check. New migrations add tables and a session
version column; existing records and fiscal snapshots are preserved.

For rollback, restore the prior code commit and image through the usual deployment
procedure. The extra tables/column may remain: old code ignores them. Previously
issued guide links stop working while old code is running. If restoring the database
as well, stop application writers first and account for payments received since the
backup; do not blindly overwrite live fiscal/payment records. Quotas already changed
in Immich require a separate explicit quota reconciliation if the catalog is reverted.
