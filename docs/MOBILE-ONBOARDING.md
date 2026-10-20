# Mobile onboarding — implementation and review

Implemented and validated on 20 October 2026. Following the user's authorization to update the Synology container, the 13 runtime files were deployed to the web service. That initial deployment preceded Git publication. The user subsequently authorized committing, pushing and running the existing NAS `update.sh`. No schema change, firewall/network edit, other service restart or production customer/payment action was part of the onboarding implementation.

## Existing flow and scope

Email verification establishes the existing time-limited authenticated portal session. Paid customers still require authoritative payment confirmation; a Checkout redirect is insufficient. Registration's encrypted temporary credential is used by the existing activation POST/worker to create Immich access with the password selected during registration. Successful provisioning removes that temporary credential. Legacy/expired credentials retain the existing password-confirmation form. Administrator-created customers who do not know their initial password must contact support; the onboarding page does not reveal it or create a recovery mechanism.

Stripe, webhook handlers, account creation, quotas, subscription lifecycle and authentication handlers are unchanged. Only presentation, language navigation and the existing browser activation/refresh trigger were changed. That trigger still submits the existing CSRF-protected activation POST; its reload budget is now bounded. Provisioning failures require an explicit retry in the browser; existing backend retries remain unchanged.

## Implementation

The ready view has three numbered sections: install official Immich, copy the public server address and sign in, then choose albums/permissions and enable backup. The server address is exactly `https://photos.zion3d.ro`. Store links are Google's `app.alextran.immich` and Apple's `1613945652`. QR SVGs are local, static, credential-free store links. No external assets or runtime libraries were introduced.

Device detection uses navigator platform/UA signals locally. Android with `Mobile` selects phone; Android without it selects tablet as a best-effort UA classification. Explicit iPhone/iPad signals select iOS; Macintosh/MacIntel with multiple touch points handles modern iPadOS. Ambiguous client hints retain an unknown category while choosing the indicated OS store. Desktop and unidentified devices display both stores and QR codes. Viewport size affects layout only. Android/iPhone-iPad/Both controls always allow overriding detection when JavaScript works. No-JavaScript users see both stores and a selectable server address. Overrides stay in page memory; device signals are not sent to a backend or analytics.

Clipboard API failures fall back to selecting the public address and `execCommand('copy')` where supported. If both fail, the address remains selected with localized manual-copy instructions and an ARIA live announcement. Browser status checks use the existing authorized GET, with delays of 5/10/20/30/45/60 seconds (six actions maximum per tab budget). The existing due activation POST can consume a budget entry after 300ms. Manual refresh resets the budget. Unavailable browser storage disables automatic navigation. No new status endpoint or provider call was added.

The presentation model distinguishes session/verification/payment/provisioning/failed/password/inactive/suspended/ready. Ready requires verified email, current entitlement, an existing storage ID and active local provisioned state. Only this ready view displays the authenticated customer's escaped email. It never displays a password, provider key, storage ID or provisioning diagnostic. Existing CSRF form fields remain necessary for billing/activation actions. Private cache, noindex and CSP behavior are retained.

Optional first-file evidence uses only the existing current-user aggregate statistics cache, matching both storage ID and current integration fingerprint, available and no older than ten minutes. Missing/stale data omits the indicator. A file count is explicitly not evidence of mobile origin or enabled background backup. No media metadata is fetched and no extra Immich API request is made during rendering.

All new UI strings are translated in Romanian, English, French and German. Language switching preserves the actual activation routes: `/ro/activare`, `/en/activation`, `/fr/activation`, `/de/aktivierung`.

Guidance was checked against the official [mobile app](https://docs.immich.app/features/mobile-app/) and [mobile backup](https://docs.immich.app/features/mobile-backup/) documentation. The reviewed documentation does not establish a supported server-configuration deep link, so none was invented. iOS scheduling limitations and targeted Android battery troubleshooting are explained in expandable sections. No third-party screenshots were reused.

## Changed files

Runtime:

- `src/Application.php` — supply read-only onboarding model and preserve language route.
- `src/MobileOnboarding.php` — new presentation-state/cache reader.
- `src/TranslationService.php`, `translations/onboarding.php` — four-language messages.
- `templates/status.php`, `templates/mobile-onboarding.php` — status and three-step layout, existing billing/upgrade links preserved.
- `templates/layout.php` — conditional local assets and refreshed site.js cache key.
- `public/assets/site.js` — move activation timer to bounded onboarding module.
- `public/assets/device-detection.js`, `public/assets/onboarding.js`, `public/assets/onboarding.css`.
- `public/assets/onboarding/android.svg`, `public/assets/onboarding/ios.svg`.

Development/documentation:

- `bin/generate-onboarding-qr.cjs` — optional build-time regeneration; no Node runtime requirement.
- `tests/mobile-onboarding.php`, `tests/device-detection.cjs` — state/cache and signal unit checks.
- `tests/setup-onboarding.php`, `tests/onboarding-browser.cjs` — CLI-only temporary authenticated fixtures, isolated SQLite/sessions, browser QA.
- `tests/run.php` — include state tests.
- `tests/http_flow.py` — update only presentation expectations, retain real local signup/provisioning adapter coverage.
- This document.

Unrelated `.codex-remote-attachments/` and `docs/infrastructure/` were preserved and are outside this change.

## Validation results

- `composer lint`: passed for all PHP files.
- `composer test`: passed, 609 assertions including 21 new state/cache/translation checks. External services are mocked or blocked.
- `python3 tests/http_flow.py`: 56 checks passed against isolated local storage/email fixtures. Includes registration, email verification, automatic activation through the real HTTP adapter, idempotency, billing profile and fiscal simulation.
- `node tests/device-detection.cjs`: 15 cases passed, including Android tablet, iPadOS Macintosh/touch, Windows/macOS/Linux, unknown and ambiguous client hints.
- `node tests/onboarding-browser.cjs`: 134 checks passed in Chromium, including store URLs, decoding both QR images, device signals, manual overrides, clipboard success/fallback/failure, all languages, 320px layouts, touch targets, portrait/landscape, no-JS behavior, expired/anonymous sessions, non-ready states, stopped polling, blocked sessionStorage and retained paid billing links.
- JavaScript syntax checks and `git diff --check`: passed.

Browser runner uses an existing Playwright installation via `PLAYWRIGHT_MODULE`. QR decode checks additionally use `QR_TEST_MODULES` pointing to a directory containing `jsqr` and `pngjs`; neither is a project runtime dependency. The runner deletes its isolated temporary database/sessions and stops its PHP server. It never creates a production customer or real payment.

Screenshots in `tests/artifacts/onboarding/` (ignored by Git): `before-desktop.png`, `before-phone.png`, `after-desktop.png`, `after-phone.png`, `after-android_phone.png`, `after-android_tablet.png`, `after-iphone-landscape.png`. Desktop, phone and tablet captures were visually inspected. Screenshots use fictitious customer emails.

## Remaining device acceptance

Browser signal emulation is not a real Android/iOS device test. Before release, check Chrome and Samsung Internet on Android, Safari on iPhone/iPad (including desktop website mode), Firefox and Edge on desktop. For each:

1. Use a controlled TEST/free account and the existing verification flow to reach activation. Check all four language links.
2. Confirm the appropriate official store opens. On desktop, scan both QR codes with a real phone.
3. Override the platform manually and verify the store/help sections change. Test portrait and landscape, zoom, keyboard navigation, visible focus and clipboard permission denial.
4. Paste the server address into the installed Immich release, sign in with that account's known password and choose a small test album. Grant the permissions you choose and enable backup in Immich.
5. Verify a test file arrives. After the existing statistics worker syncs, reload activation to see the first-file indicator. Do not interpret it as proof of background scheduling.
6. Verify pending/failed/suspended states with dedicated fixtures. Confirm billing management/history and any eligible upgrade link remain usable. Do not simulate these by editing a real customer's entitlement.

## Deployment and rollback

Read-only inspection confirmed passwordless Docker access through `ssh zion` and `sudo -n /usr/local/bin/docker`. The live web container uses read-only bind mounts from `/volume1/docker/zion-storage-subscription/htdocs/{src,public,templates,translations,config,bin,legal}`, persistent data at `data`, and an authoritative Composer class map. Current Compose has only `web`, build context `htdocs`. A new class therefore needs a rebuilt application image, not just copying PHP files or restarting the old image. Do not replace NAS Compose with the repository's generic Compose: it differs from this installation.

The deployment used a private NAS-local staging directory at `/volume1/docker/zion-storage-subscription/releases/onboarding-20261020`. Five replaced source files were checksum-matched against repository HEAD before deployment. All 13 runtime files have a SHA-256 manifest; existing files were copied to `backup/`, and the previous image was retained as `zion-storage-rollback:onboarding-20261020`. No environment file was copied into the release image/context. Application settings and persistent data were not exported.

An initial full offline build could not use the OS dependency cache and failed before changing production source. The final release instead extends the exact existing application image, copies the NAS code plus the reviewed overlay, and regenerates Composer's authoritative map with scripts/plugins disabled. It was built with `--pull=false --network=none`; no OS/library/dependency updates occurred. Original image layers were verified as an unchanged prefix of the new image.

The first switch was automatically rolled back because a comparison of the ordered Docker environment list differed. The comparator was changed to normalize the list, and the effective environment was separately verified against persistent Compose plus original image defaults. Saved integration settings were successfully decrypted without displaying credentials or making provider API calls. The successful second switch compared environment values, mounts, ports, networks/aliases, restart policy, command and security options against its pre-switch state. Do not interpret the first ordered hash alone as evidence of changed secret values.

Deployed image: `sha256:4152dde778d8570a83bd7afe752fcbac219b0bfee9e991d0d2b13a02bbf58852`. Thirteen public HTTPS checks passed: four homepage locales, four anonymous activation routes, three JS/CSS assets and two QR SVGs. Anonymous activation remains private/no-store and reveals no customer data. The autoloader and QR readability were checked as `www-data`. No PHP fatal errors/warnings were observed in the deployment verification window. Authenticated production customer actions and real mobile uploads were not performed.

To restore this release's predecessor, run the retained guarded rollback helper:

```sh
ssh zion "python3 /volume1/docker/zion-storage-subscription/releases/onboarding-20261020/deploy.py rollback"
```

It stops only web, restores backed-up source, moves newly introduced files into the private release directory, restores the recorded old image/tag and starts only web using the existing Compose configuration. Verify public/authenticated pages afterward. It leaves `.env`, database, persistent customer data and other services alone. Keep the rollback image and release directory; do not prune them.

For a future release, the general process is:

1. Review this change and device checks. Approve the release snapshot separately from any Git publication. No release archive should include `.env`, `storage`, `data`, private infrastructure documents or attachment folders.
2. Recheck container image ID, configured image name, mount paths and current Compose before changing anything. Save the image ID/name privately. Retain the old image under a unique rollback tag. With `umask 077`, make a NAS-local code backup of the affected `htdocs` paths. Preserve the existing protected database/APP_KEY backup according to the deployment procedure; do not export or print credentials. This change does not migrate the database.
3. Stage only the reviewed runtime files in the existing `htdocs` tree, preserving ownership and read-only mount arrangement. No dependency lock or Dockerfile change is required. Validate PHP syntax before switching the image. Avoid serving a partial upload; use the established maintenance/release staging process.
4. Build the staged application image against the approved existing runtime, refresh its authoritative class map and verify PHP syntax before switching. Keep the old image/tag for rollback. Retag the approved new image to the image name used by the existing Compose configuration (`zion-storage-web` for this installation), then switch only web. Do not pull updated base images, use `down`, remove volumes, or alter networks/firewall/other services:

   ```sh
   cd /volume1/docker/zion-storage-subscription
   sudo -n /usr/local/bin/docker compose -f compose.yaml up -d --no-deps --no-build web
   ```

5. Verify the rebuilt class map includes `Zion\MobileOnboarding`, the web container is running with the same mounts/networks/ports, public pages respond in all four languages and anonymous activation exposes no account data. Check a controlled authenticated TEST customer's activation, clipboard/store links, billing links and server logs for new exceptions. Avoid real charges or recreating any existing Immich customer.
6. Rollback if verification fails: restore the backed-up code paths (including old `site.js`/layout/translations), retag the recorded old image ID to the recorded Compose image name, then run the same `up -d --no-deps --no-build web` command. Verify the old page, public routes and original mounts/networks. Keep data, `.env`, APP_KEY and database untouched. Keep the rollback image/backup until acceptance; do not prune.

The rebuild persists the new autoloader across container recreation. A one-off `composer dump-autoload` inside the old container would not provide that persistence, so it is not the proposed deployment method.
