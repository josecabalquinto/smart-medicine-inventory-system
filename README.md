# SmartMed - Medicine Monitoring with Smart Scale for Medicine Counting

Student proof of concept extending the installed Laravel Livewire starter kit. One physical scale, one balance per medicine, staff-confirmed replacement counts, and read-only adjustment history. No sales, batches, locations, or dispensing verification.

## Requirements and installation

PHP 8.3+ (developed with 8.4), SQLite/PDO SQLite, Composer, Node.js 22+ and npm. Installed versions: Laravel 13.42, Livewire 4.4.8, Flux Free 2.21, Tailwind 4.3.5, Pest 5.3.7. Major versions were retained.

```sh
composer install
npm ci
# On a new installation only: copy .env.example to .env
php artisan key:generate
# Ensure database/database.sqlite exists; create an empty file if needed.
php artisan migrate
php artisan db:seed
npm run build
php artisan serve --host=127.0.0.1 --port=8765
```

Open http://127.0.0.1:8765. The existing port 8000 may belong to another local application. For asset development, run `npm run dev` in another terminal. The native Vite config loader avoids a Windows native-dependency bundling error in the installed Vite Plus version. No package upgrade is required. Fonts are built into local assets by the starter's existing font integration.

Keep `DB_CONNECTION=sqlite`, `CACHE_STORE=file`, and `SESSION_DRIVER=file` for the single-host prototype. File cache supports the atomic locks used by the application while keeping high-frequency telemetry, rate-limit, and browser-session writes out of the durable SQLite inventory database; Redis is unnecessary. `APP_TIMEZONE` is not used: server/audit timestamps are UTC and screens display stored UTC times. Local sessions expire after 180 seconds without polling; explicit Finish / release frees the scale immediately. Count Stock polls every five seconds, dashboard every ten.

## Local demo accounts

Development seeders run only in local/testing and do not overwrite existing accounts:

| Role | Email | Local-only password |
| --- | --- | --- |
| Admin | admin@dispensa.test | Demo-only-2026! |
| Staff | staff@dispensa.test | Demo-only-2026! |

These are intentionally documented demo credentials. Never use the demo database/accounts in production. Public registration is disabled. For a clean deployed database, run `php artisan inventory:create-admin` interactively to create a private administrator; passwords are hidden and hashed. Admins create additional staff in Users. Disabled accounts cannot log in or continue sessions. The last active admin cannot be demoted/disabled. Self-deletion is disabled to retain staff references in audit history.

## Staff workflow

1. Admin registers a medicine with its exact strength, form and manufacturer. Archived medicines remain in history and cannot receive counts.
2. In Count Stock, search/select the medicine and acquire the scale session.
3. Load its active saved profile, wait for acknowledgement, and tare the empty tray. For new calibration, use the four physical buttons, explicitly save the capture to the selected medicine, load that profile, and tare again.
4. Confirm identity, load **all** stock represented by the balance, enter a reason, and review the count and adjustment.
5. Save Count replaces the balance once and records history. An estimated/live reading never updates stock automatically. A partial tray must not be saved as total inventory.
6. Finish / release when done. A closed browser cannot permanently reserve the scale.

Manual counts are explicitly labeled, require quantity/reason/identity confirmation, and retain the staff member and method. Correct an error with a new audited adjustment; history has no ordinary edit/delete actions. A shortage is an adjustment, not a sale. `Not counted` is separate from a confirmed zero.

Scale confirmation validates the exact reviewed server snapshot, active profile/version, matching acknowledged session, boot, fresh tare, sequence, freshness, stable/calibrated state, identity association and stock revision. Raw readings are cross-checked against the signed factor. New telemetry is never silently substituted for the reviewed snapshot. If a review goes stale, close it and review a new reading. A unique idempotency key returns the existing result for an identical retry; conflicting reuse fails.

## Isolated development simulator

Set `INVENTORY_SIMULATION=true` in local `.env`, then `php artisan config:clear`. The current development workspace has it enabled. Open `/simulator`; use its link to `/count-stock?simulation=1` in a second tab. Run the on-screen sequence: acquire session; Reboot; Tare; one-piece SAMPLE; ten-total SAMPLE; CALIBRATE; explicitly save capture; load profile; acknowledge; fresh Tare; Stable reading; review/save.

Enable "Publish current reading every 2 seconds" to keep a demo reading fresh while reviewing in the other tab; it preserves the current stable/unsettled state. Disconnect/stale/out-of-order controls stop publishing.

Controls also exercise instability, disconnect, stale arrival, out-of-order sequence, NEW and reboot. Out-of-order events intentionally produce validation errors. After an idle interval, send another stable reading before saving. Simulator controls use the same Form Request rules, reading, calibration, command and inventory services as the API. There is no artificial accuracy claim.

Isolation is explicit and server-enforced: demo medicine rows and a dedicated demo scale have `simulation=true`; pairing with physical records is rejected. Demo counts are labeled `simulation`, retain their manual/scale method, and are excluded from dashboard inventory summaries. Normal Medicines/Count Stock show physical inventory. History/profiles identify demo records. No request parameter can switch a scale count into a physical balance. The simulator and demo saves are refused outside local/testing even when the flag is true. The physical API never authenticates a simulator scale.

## Device setup and documentation

Admin Settings configures the one physical device, display name, active status and freshness thresholds (default stale 10s/offline 30s). Provision/rotate its credential and copy the plaintext once. Only a hash persists. Set the matching device ID/credential in an ignored firmware configuration. Rotation invalidates the previous credential immediately.

- [Device API and JSON examples](docs/device-api.md)
- [ESP32 integration and hardware validation checklist](firmware/README.md)
- [Request examples](docs/device-api.http)

The working Arduino sketch has now been supplied and is preserved under
`firmware/medicine-counter-local`. A separate connected version under
`firmware/medicine-counter-connected` implements the documented protocol while
keeping the original pins and local controls. It has not yet been compiled with
the user's Arduino installation, uploaded, or tested on the physical assembly.
Simulation does not resolve or validate the hardware's occasional missed
single-piece change.

## Checks

```sh
php artisan test
php vendor/bin/pint --test
php vendor/bin/phpstan analyse --memory-limit=512M
npm run build
php artisan view:cache
```

Pest uses an isolated in-memory SQLite database. The suite covers authorization, disabled accounts, dedicated device auth, signed numeric calibration, profile versions/acknowledgements, fresh tare, stale/unstable readings, reboot/sequence rules, ownership/expiry, stock replacement/revisions, idempotency, manual counts, archived medicines, transaction rollback, simulator isolation and Livewire review/confirm. Browser checks exercise desktop (1440px) and phone (390px) screens and a complete simulated calibration/count save. Those checks are not hardware verification.

## Backup and restore

Use SQLite's backup API, not a live copy of only the main file (WAL/SHM files may contain recent commits). With Python's standard library:

```sh
python scripts/backup_sqlite.py database/database.sqlite backups/dispensa-2026-11-17.sqlite
```

The script refuses to overwrite an existing destination and runs `PRAGMA integrity_check`. SQLite CLI users can instead use `.backup 'backups/dispensa.sqlite'`, or `VACUUM INTO 'backups/dispensa.sqlite'` with a new destination. Retain encrypted/off-device backups and the deployment's APP_KEY securely; do not commit either.

Restore in maintenance mode with **all web/device requests and queue/background writers stopped**. Take a verified backup of the current DB first. With every SQLite connection closed, replace database/database.sqlite with a verified backup. Remove old `-wal`/`-shm` sidecars only while all writers are stopped; never mix sidecars from another DB. Check `PRAGMA integrity_check`, run pending migrations, `php artisan cache:clear` and `php artisan up`, then restart services. Cache clearing forces fresh telemetry; start a new scale session, reapply profile and tare. Do not reuse a pre-restore browser confirmation. Keep the matching APP_KEY to preserve encrypted sessions/configuration.

## Deployment boundaries

Use HTTPS, `APP_ENV=production`, `APP_DEBUG=false`, a private APP_KEY and normal secure session-cookie settings. The device API rejects non-HTTPS outside local/testing. If TLS terminates at a reverse proxy, configure Laravel trusted proxies for that deployment; do not bypass the HTTPS check. Keep `INVENTORY_SIMULATION=false`. Run migrations and create a private admin; do not load DemoSeeder. Rate limits are 120 device requests/minute per client IP; publish about once every two seconds plus sparse command polls. On a shared network this budget applies collectively. Payloads are limited to 8 KiB. Device routes use dedicated credentials and are separate from browser session routes; browser CSRF protection is retained.

A 1 kg load-cell rating includes platform, tray and contents. This app uses raw sensor units, not grams, an accuracy percentage or a measured overload threshold. Staff must verify stock/medicine identity independently. No claim is made that a stable estimated count is accurate enough for dispensing.
