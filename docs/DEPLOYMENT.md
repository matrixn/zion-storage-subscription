# Deployment

## Container deployment

```sh
cp .env.example .env
# Generate APP_KEY, configure ADMIN_EMAIL/ADMIN_PASSWORD, SMTP and storage API; configure Stripe TEST/LIVE profiles and automatic catalog sync in /admin.
# Set APP_URL=https://storage.zion3d.ro and APP_ENV=production.
docker compose build
docker compose run --rm --user www-data web php bin/console.php install
docker compose up -d
```

The named volume holds SQLite, encrypted mail outbox, private logs and locks. Initial directory ownership is prepared for www-data. Execute all CLI tasks as www-data to avoid root-owned lock files. Never mount the project root as the public document root.

Run from the deployment scheduler:
- Every minute: `docker compose exec -T --user www-data web php bin/console.php mail:work`
- Every minute, before fiscal/mail workers: `docker compose exec -T --user www-data web php bin/console.php changes:retry`
- Every hour: `docker compose exec -T --user www-data web php bin/console.php quota:check`
- Daily: `docker compose exec -T --user www-data web php bin/console.php maintenance`

These commands are instructions, not schedules installed on the current host. Configure log rotation for storage/logs/application.log and local mail previews. Production must use MAIL_TRANSPORT=smtp; local file previews are prohibited in production.

Also run `docker compose exec -T --user www-data web php bin/console.php oblio:retry --due` every minute, before the mail worker. Run migrations before serving the updated application. Oblio defaults to OBLIO_ENABLED=false; enabling it can issue real fiscal documents. See [the fiscal deployment notes](OBLIO.md) for issuer/VAT settings, simulation boundaries, retries and external acceptance. Back up APP_KEY with the database; it is required to decrypt persistent fiscal snapshots as well as pending email.

SMTP supports mandatory STARTTLS (`MAIL_ENCRYPTION=tls`) or implicit TLS (`smtps`). Credentials are URL-encoded internally. Do not use unencrypted SMTP in production.

## Reverse proxy

Publish only the loopback port or an isolated proxy network. Terminate HTTPS on the trusted proxy, preserve Host, overwrite X-Forwarded-Proto with https, and set X-Forwarded-For using the proxy's observed client address. Set TRUSTED_PROXIES to exact proxy IPs, comma separated; no wildcard trust. The rate limiter walks the forwarded chain from the trusted edge and ignores arbitrary prefixes.

APP_URL is the authoritative URL source for canonical links, verification links and Checkout return addresses. It prevents Host-header/private-port redirects. Set it to https://storage.zion3d.ro. Secure cookies use this configured HTTPS origin rather than trusting arbitrary request headers. Do not expose direct HTTP access around the proxy. Remove product/software identification headers in the proxy.

Example Nginx location (adjust upstream to the configured loopback binding):

```nginx
location / {
    proxy_pass http://127.0.0.1:8088;
    proxy_set_header Host storage.zion3d.ro;
    proxy_set_header X-Forwarded-Proto https;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    client_max_body_size 1m;
}
```

Application responses include CSP, frame protection, referrer protection and no-sniff headers. HSTS is emitted in production. Configure web-server request size limits (1 MB) and request timeouts. Never log request bodies. Do not log verification-token query strings in the proxy/access logs: use path-only logging for verification routes.

## Persistence and concurrency

Designed for a single instance with SQLite on local persistent storage and filesystem locks. Do not share SQLite across NFS or run independent replicas. A multi-instance deployment requires a transactional shared database and distributed locks.

Back up the SQLite database consistently (SQLite backup API or a stopped application) together with APP_KEY and any needed pending mail records. Store keys outside the repository and protect backups. Test restoration before launch. A storage backend backup is a separate operational responsibility.

No production deploy, real customer mutation, live payment, destructive storage API call or external email send was performed in development.

For `/admin`, manual customer grants, Stripe plan changes and full Immich access suspension, follow [administration and upgrade deployment](ADMIN-AND-UPGRADES.md). Run installation/migration before serving this version. Full ban enforcement needs the private Immich auth_request gateway and a firewall preventing direct upstream access; do not set STORAGE_GATEWAY_ENFORCED until verified.

## Automatic storage activation

Run migrations before serving this release. New registrations retain an APP_KEY-encrypted onboarding password for at most 24 hours; successful provisioning deletes it immediately. The normal password hash remains the authentication credential. Do not rotate APP_KEY while pending jobs exist without planning recovery. Expired/missing credentials require one password confirmation; no hash can reconstruct an existing customer's password.

The existing every-minute `changes:retry` worker also retries automatic activation, even when the customer has closed the browser. Alternatively schedule `php bin/console.php activation:retry` every minute. Run `maintenance` daily to purge expired credentials. These are scheduler instructions, not automatically installed tasks. API journal Immich entries show failures. Retries have a shared 60-second backoff and require verified email, accepted terms, an eligible current plan and no ban. No storage calls are added to the webhook response path. The authenticated status page updates itself and posts a CSRF-protected activation request after payment is confirmed; GET polling does not create accounts.
