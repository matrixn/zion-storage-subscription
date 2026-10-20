# Immich configuration and statistics

Use **Configurare → API Immich** (`/admin?section=immich`) to save the API URL and administrator API key. A URL without `/api` gets that suffix automatically. Production requires HTTPS; HTTP is available for isolated development fixtures. Userinfo, query strings and fragments are rejected. Credentials are encrypted with APP_KEY. Saved key inputs contain only the first seven and last six characters; submitting the unchanged mask retains the full key. Clearing a saved key disables the integration and never restores environment fallback.

Before the first save, STORAGE_API_URL and STORAGE_API_KEY remain the fallback. After a save, the whole stored Immich configuration is authoritative for account creation/quota operations, usage checks, explicit TEST cleanup and the access gateway. Existing STORAGE_GATEWAY_KEY / enforcement settings remain independent proxy controls.

Create a dedicated API key in an Immich administrator's Account settings → API Keys. Required statistics permission: `server.statistics`. Existing provisioning also requires `adminUser.read`, `adminUser.create`, `adminUser.update`; optional TEST removal needs `adminUser.delete`. The connection test reads `/api/server/statistics` and validates its response; it does not create test users or prove write permissions. Network/provider errors are recorded in the existing Immich API journal.

## Collection and reporting

The service reads Immich's official `GET /server/statistics` response (`usage`, `photos`, `videos`, `usageByUser`). It maps rows using existing `users.storage_user_id`, never by name/email and never imports unrelated Immich accounts. It persists counts/bytes, remote quota and collection time, not filenames, thumbnails or asset lists. Counts/usage are the statistics reported by Immich, not physical NAS disk/free-space metrics. Media totals may exclude thumbnails, transcodes or other filesystem overhead according to the installed Immich version.

The dashboard reports the sum for linked Zion clients with available statistics, photo/video totals, coverage and a top ten ordered by bytes used. Each row shows decimal GB used / selected Zion plan capacity and a percentage. Restricted/banned clients still retain their statistics; a temporary remote quota restriction does not shrink the plan denominator. All-client management shows these figures per client as well. Statistics cover managed clients across TEST/LIVE; physical usage is not split by billing currency/mode.

A successful complete payload replaces the current snapshots atomically. Provider failures or invalid counts preserve previous confirmed values. Missing remote users are marked unavailable, not zeroed. Changing URL/key invalidates the visible cache and connection test until collection succeeds again. Rows cascade when a client is deleted. The dashboard performs local reads only and labels collection failures/missing data/values older than ten minutes; per-client timestamps are shown. Unmatched accounts aren't included in the Zion total; coverage prevents partial data being mistaken for complete collection.

## Five-minute schedule

Run migrations before serving the release:

```sh
php bin/console.php migrate
```

Docker Compose includes an `immich-worker` service sharing the same storage and configuration as `web`, running as www-data. Deploy/rebuild both services:

```sh
docker compose build
docker compose run --rm --user www-data web php bin/console.php migrate
docker compose up -d web immich-worker
```

The worker starts a read-only collection and then runs on five-minute clock boundaries. Failures do not stop the following collection. The due guard prevents manual runs and the worker from duplicating a collection in the same five-minute slot; an exclusive non-blocking lock prevents concurrent jobs. Leave existing activation, billing, fiscal and mail schedules running.

For Synology Web Station / native PHP, create a user-defined Task Scheduler entry every five minutes under the application user with a PHP 8.4 executable and the absolute project path:

```sh
/absolute/path/to/php /absolute/project/path/bin/console.php immich:sync --due
```

Equivalent user crontab (adjust paths; no sudo/root needed):

```cron
*/5 * * * * /absolute/path/to/php /absolute/project/path/bin/console.php immich:sync --due >> /absolute/project/path/storage/logs/immich-cron.log 2>&1
```

Only install one scheduler for a deployment. The CLI is not an unauthenticated public HTTP endpoint. No host crontab or Synology scheduler is changed by local development; enabling the compose service provides the included automatic schedule. After saving API credentials, manual sync can populate the dashboard immediately. This schedule only collects statistics and does not change plans, quotas, files or subscriptions.

Reference: [Immich official OpenAPI](https://github.com/immich-app/immich/blob/main/open-api/immich-openapi-specs.json). Validate against the installed version and grant the required administrator permission.

## Initial preferences for new accounts

The installed server reported **Immich 3.3.3** on 20 October 2026. New accounts created by the portal now receive explicit account preferences before storage activation is marked successful:

| Preference | Initial value |
| --- | --- |
| Google Cast | Disabled |
| People and its sidebar entry | Disabled |
| Memories | Enabled |
| Other account preferences | Standard Immich values |

These are starting preferences, not enforced restrictions; customers can change them in Immich. Existing activated customers are not retroactively updated. No albums, permissions, uploads, quota or billing settings are changed by this initialization. Maps remain governed by the existing server/browser configuration.

Immich's theme and language onboarding steps use **device/browser-local** preferences. They are not fields in this version's account preferences API. The portal does not fabricate language/theme payloads, modify Immich's frontend, or attempt to write cross-origin localStorage. Customers retain Immich's normal browser defaults and can adjust them in Immich settings. Mobile backup albums/OS permissions also require customer action.

Provisioning creates or reconciles only the exact matching portal storage label/email, then uses the already available registration password to open a transient user session. The user ID/email and non-admin role are verified before using user endpoints. No administrator API key is attached to login or user-session calls. Preferences are updated and their response values checked before `PUT /users/me/onboarding` is sent. Only then can the existing portal activation finish. The transient session is logged out, without invalidating other devices. Tokens stay in request memory and are redacted from API diagnostics, including error messages that echo a raw token.

An outage or invalid confirmation leaves activation retryable through the existing encrypted-credential/backoff mechanism. A remote account is not created twice. If remote onboarding is already complete on a retry, preferences are left alone. No background job sweeps existing accounts or resets customer choices. A failed logout is surfaced through the API journal and retry path; it does not persist the bearer token locally.

**Separate server setting:** the initial read-only diagnostic found Machine Learning, facial recognition and maps enabled. With explicit operator approval, global facial recognition was subsequently disabled while all other fields were preserved. Disabling the per-user People preference does **not** disable server-side face analysis. Existing face metadata was not deleted. The general configuration page below now provides explicit administrator control of these server flags.

Contracts checked against the official tagged source: [user API and onboarding](https://github.com/immich-app/immich/blob/v3.3.3/server/src/controllers/user.controller.ts), [preference schema](https://github.com/immich-app/immich/blob/v3.3.3/server/src/dtos/user-preferences.dto.ts), [onboarding steps](https://github.com/immich-app/immich/blob/v3.3.3/web/src/routes/auth/onboarding/+page.svelte), [user privacy](https://github.com/immich-app/immich/blob/v3.3.3/web/src/routes/auth/onboarding/OnboardingUserPrivacy.svelte).

Validation: PHP lint, 626 backend assertions and 60 isolated HTTP assertions passed. Coverage includes setup order, identity/admin mismatch, preference failure/invalid response, retries without duplicate creation, preserving completed preferences, logout and transport credential separation/redaction. Protocol tests use local fixtures; no production customer was created, logged into or reconfigured during validation. A controlled real-device signup remains an operator acceptance check.

Release through the existing NAS `/volume1/docker/zion-storage-subscription/update.sh` after Git publication. Revert the application commit and run the same script to stop applying this profile to future creations. Rollback does not erase preferences or onboarding already saved for accounts created under this version, and does not change their files or subscriptions.

## General configuration in Zion Admin

Open **Configurare → Immich · General** (`/admin?section=immich-general`). The menu retains at most two levels. API credentials and statistics remain on the existing **API Immich** page; both pages link to each other and to API diagnostics.

The server form reads current values directly from Immich and controls four global flags: Machine Learning, facial recognition, smart search and maps. Changes apply only after an authenticated, CSRF-protected explicit save. Recognition and smart search require the Machine Learning engine to be enabled; their individual flags can remain configured while the engine is off. Open clients may need reloading. Toggling a function does not delete customer files or previously generated metadata. Processing already in flight may finish; future jobs use the updated server configuration.

The second form stores Google Cast, People and Memories as initial preferences for **future accounts**, in the persistent Zion database under `immich:account-defaults:v1`. It works independently of the remote server and does not change existing activated customers. A newly provisioned account reads this saved profile rather than hardcoded flags. The default before the first save is Cast off, People off, Memories on. Enabling People also enables its sidebar entry for new accounts. Other preferences retain Immich defaults. These initial values are not permanent restrictions on customer settings.

The server API key requires `systemConfig.read` and `systemConfig.update` for the compatible `/system-config` endpoint. If Immich uses `IMMICH_CONFIG_FILE`, its API rejects configuration changes; Zion shows safe guidance and the API journal records the failure. Do not change global secrets through this page.

Safety: only allowlisted booleans and an opaque HMAC revision reach the HTML. Full server configuration and its secrets stay in request memory. A save re-reads the current configuration, rejects stale forms/changed API targets and performs a second read before updating. The request preserves all unrelated fields and verifies the resulting configuration with another GET. A local lock serializes saves from Zion. Immich's full-config API does not offer atomic compare-and-swap: an external operator could still edit in the narrow interval between the final read and write, so avoid concurrent configuration changes in both interfaces. Uncertain outcomes are reported without pretending an automatic rollback happened. Administrator audit records contain only booleans/result summaries; transport errors appear in the API journal.

General-configuration coverage: 645 backend assertions, 73 isolated HTTP checks and 15 Chromium browser checks passed. These cover changed defaults reaching new provisioning, enabling/disabling recognition against a local API fixture, preserving unrelated secrets/auth/maps, stale/concurrent edits, CSRF/authentication, permission failures, no configuration leakage, keyboard operation and layouts from 320px through desktop. Desktop/phone/tablet screenshots are in `tests/artifacts/immich-general/` (ignored by Git). Tests do not toggle production features. Deployment and opening this page also do not activate any function automatically.

Rollback the application via Git and the existing update script if needed. Saved local defaults and remote global flags persist independently; removing the UI is not a reversal of server choices. Restore previous toggle values explicitly before removing the page if that is the intended rollback.
