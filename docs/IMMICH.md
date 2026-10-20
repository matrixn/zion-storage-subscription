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

**Separate server setting:** the read-only diagnostic found `machineLearning.enabled=true`, `machineLearning.facialRecognition.enabled=true`, and `map.enabled=true`. Disabling the per-user People preference does **not** disable server-side face analysis. This implementation does not change those global settings or delete existing face metadata. Global recognition configuration requires a separate operator decision.

Contracts checked against the official tagged source: [user API and onboarding](https://github.com/immich-app/immich/blob/v3.3.3/server/src/controllers/user.controller.ts), [preference schema](https://github.com/immich-app/immich/blob/v3.3.3/server/src/dtos/user-preferences.dto.ts), [onboarding steps](https://github.com/immich-app/immich/blob/v3.3.3/web/src/routes/auth/onboarding/+page.svelte), [user privacy](https://github.com/immich-app/immich/blob/v3.3.3/web/src/routes/auth/onboarding/OnboardingUserPrivacy.svelte).

Validation: PHP lint, 626 backend assertions and 60 isolated HTTP assertions passed. Coverage includes setup order, identity/admin mismatch, preference failure/invalid response, retries without duplicate creation, preserving completed preferences, logout and transport credential separation/redaction. Protocol tests use local fixtures; no production customer was created, logged into or reconfigured during validation. A controlled real-device signup remains an operator acceptance check.

Release through the existing NAS `/volume1/docker/zion-storage-subscription/update.sh` after Git publication. Revert the application commit and run the same script to stop applying this profile to future creations. Rollback does not erase preferences or onboarding already saved for accounts created under this version, and does not change their files or subscriptions.
