# Listmonk transactional email

Open `/admin?section=listmonk` → **Configurare / Email / Listmonk**. Configure the five LISTMONK fields, enable the transport, and save. The encrypted database profile is authoritative as a whole after first save; blank saved fields never fall back to environment credentials. Tokens display only the first 7 and last 6 characters in the input (short tokens are fully masked). Blank edits and unchanged masks preserve the token; explicit removal requires disabling the integration first.

## Listmonk setup

- Configure SMTP in Listmonk itself and authorize the sender domain/address.
- Create a dedicated API user under Admin → Users with transactional send and template read permissions. Use that user's API username and token, not an administrator login password.
- Create a **Transactional** template (`type: tx`) under Campaigns → Templates. Copy its numeric ID from the edit URL. Its body should contain `{{ .Tx.Data.html | Safe }}` to render the complete, escaped Zion email. No tracking pixel or tracked links are needed. Subject, text, locale and kind are also available in `.Tx.Data`.
- LISTMONK_URL is the base URL, optionally including the deployment subpath; do not append `/api/tx`. HTTPS is mandatory in production. Redirects are not followed, TLS verification stays enabled, and calls have bounded connection/response timeouts.
- Test the saved connection (authenticated template GET), then explicitly send a test email. A successful API response means Listmonk accepted the message, not guaranteed inbox delivery; inspect Listmonk SMTP logs and spam folders too.

Zion sends `POST /api/tx` with Basic API authentication, `subscriber_mode: external`, the configured template/from address, localized subject, rendered HTML and alternate plaintext. This requires a Listmonk version supporting external recipients. It never creates subscribers or enrolls customers into campaigns. Existing emails (verification/activation, billing, fiscal invoices and service notifications) keep their outbox, locale, deduplication and encrypted pending payloads.

## Worker and fallback

New account confirmations and public resend requests attempt immediate, targeted Listmonk delivery after the account/token transaction commits. API failures retain the encrypted message for retry. Expired/replaced/consumed confirmation links are discarded before transmission; the customer can request a fresh link using Resend.

The admin Listmonk page shows pending/unattempted/failed mail counts and actual CLI worker activity (a connection test never updates the worker heartbeat). Its CSRF-protected manual queue action processes up to five pending messages without a worker; it does not replace automatic retries.

Docker Compose includes `mail-worker` (15-second polling, same storage volume). Rebuild/restart Compose to start it. For a PHP/Synology deployment, schedule `php /absolute/project/bin/console.php mail:work` every minute as the application user. Run only one worker where practical; the existing lock coordinates delivery workers. A Listmonk error keeps the outbox entry pending and does not silently switch to SMTP. As with SMTP, a timeout after upstream acceptance can cause a duplicate on retry; API acceptance does not provide exactly-once delivery.

Before saving the profile, LISTMONK_URL enables environment configuration. After saving, all LISTMONK_* environment values are ignored. Disabling the saved integration restores the existing MAIL_TRANSPORT log (development only) or SMTP behavior; explicit `MAIL_TRANSPORT=listmonk` fails closed while disabled. Production permits MAIL_TRANSPORT=smtp or listmonk. Existing SMTP deployments can keep MAIL_TRANSPORT=smtp and enable Listmonk in admin; no env change is needed for those deployments.

## Diagnostics

`Jurnal administrare → Comunicare API → Listmonk` contains each connection/send attempt, HTTP status and redacted provider/transport errors. Tokens, Basic credentials, recipients, email bodies and action links are not persisted in the API journal. No real credentials or external sends are required for the fixture tests. Live delivery must be confirmed against the operator's configured Listmonk installation.

Official contracts: [transactional API](https://listmonk.app/docs/apis/transactional/), [templates](https://listmonk.app/docs/apis/templates/), [templating and Safe](https://listmonk.app/docs/templating/).
