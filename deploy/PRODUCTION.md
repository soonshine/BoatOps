# BoatOps Production Runbook

BoatOps uses one real operating runtime:

`https://boatops.ayany.com/`

This runbook intentionally stays small. It exists only to make a production change repeatable, observable, and reversible at the code level.

## 1. Permanent runtime contract

- GitHub `main` is the code source of truth.
- Production PostgreSQL is the operational-data source of truth.
- Deploy an exact 40-character Git SHA, never an unpinned branch working tree.
- Do not edit application source directly on the server.
- A permanent TEST/staging environment is not required.
- Synthetic/destructive tests must never target production data.

Server layout:

```text
/www/wwwroot/boatops.ayany.com/
├── current -> releases/<release-id>
├── releases/
└── shared/
    ├── .env
    └── storage/
```

The committed Nginx and scheduler files already target this layout.

## 2. Production `.env`

`/www/wwwroot/boatops.ayany.com/shared/.env` is server-local and must never be committed.

Required production safety/runtime values include:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://boatops.ayany.com
APP_KEY=<preserve the established server-local value>

DB_CONNECTION=pgsql
DB_HOST=<server-local value>
DB_PORT=5432
DB_DATABASE=<secret/local value>
DB_USERNAME=<secret/local value>
DB_PASSWORD=<secret/local value>

```

Keep `APP_KEY` from the established runtime. Never regenerate it during a normal deploy. The deploy script checks that it is present but never prints or replaces it.

The Demo variables are optional in the production `.env`. When they are absent, the application defaults are fail-closed: `enabled=false`, `mode=disabled`, `isolated_dataset=false`, and `allow_production_seed=false`. If `BOATOPS_DEMO_SITE_ENABLED` is present, it must be `false`; the deploy script rejects an explicit attempt to enable the Demo site. Do not seed fictional Demo data in the production database.

`SESSION_DRIVER` and `CACHE_STORE` are runtime-specific and are not pinned by this deployment contract. The deploy script leaves established server-local values untouched and does not fail merely because either key is absent; the selected backends are exercised by the authenticated Operator smoke.

## 3. One-time server wiring

If not already installed from the earlier BoatOps deployment:

- Nginx site: `deploy/nginx/boatops.ayany.com.conf`
- Scheduler: `deploy/cron/boatops-scheduler`
- Certificate renewal: `deploy/cron/boatops-cert-renew`

The scheduler is a required production dependency. Before every deployment, `/etc/cron.d/boatops-scheduler` must be readable and contain a once-per-minute `artisan schedule:run` entry. The deploy script fails before creating a release when that entry is absent or malformed; `routes/console.php` owns the `holds:expire` schedule invoked by it.

The queue worker is not a current deployment or live gate. At approved application target `cf49e11376eba356eeff855856d09d11637780c9`, the application has no `ShouldQueue` implementation, queued Job/Listener, `dispatch()` path, or Queue/Bus facade call. The Operator booking path calls `ConfirmBookingAction` synchronously (`app/Http/Controllers/Operator/BookingWorkflowController.php:36`), and the required scheduler calls `ExpireDueHolds` synchronously (`app/Console/Commands/ExpireHolds.php:17`). Application actions insert outbox rows inside their database transactions (for example, `app/Application/Holds/ExpireDueHoldAction.php:64`), but there is no current in-repository queue consumer. `deploy/systemd/boatops-queue.service` remains only as a future wiring reference until a concrete queued workload exists.

Before real use, verify:

```bash
/www/server/nginx/sbin/nginx -t
curl -fsS -H 'Host: boatops.ayany.com' http://127.0.0.1:18081/up
```

## 4. Every production deployment

### A. Verify the candidate

The exact candidate SHA must have the relevant automated checks passing. Schema-changing work must have migration/rollback validation against an isolated database before production.

### B. Back up PostgreSQL

Create and verify a restorable PostgreSQL backup using the server's approved backup path. Do not put backup contents or credentials into Git.

The deploy command deliberately requires the operator to acknowledge this step.

### C. Deploy the exact SHA

From any current BoatOps checkout on the server:

```bash
sudo bash deploy/scripts/deploy-production.sh <40-character-git-sha> --backup-confirmed
```

The script:

1. refuses non-production `.env` values;
2. creates a new immutable release directory;
3. checks out the exact requested Git SHA;
4. reuses shared `.env` and `storage`;
5. installs the locked Composer dependencies;
6. inspects the checked-out release and builds locked frontend dependencies only when Vite/Mix assets are required;
7. runs production migrations;
8. atomically switches `current`;
9. verifies `/up`, `/ -> /operator/today`, and unauthenticated `/operator/today -> /operator/login`;
10. restores the previous code symlink automatically if smoke checks fail.

The release-content check treats `public/build/manifest.json`, `public/mix-manifest.json`, an unguarded Blade `@vite`/`Vite::asset`, or a Blade `mix()` call as requiring frontend assets. In that case npm, `package.json`, and `package-lock.json` are mandatory; the deploy runs `npm ci --ignore-scripts`, `npm run build`, and fails closed unless a Vite/Mix manifest exists afterward.

The approved target `cf49e11376eba356eeff855856d09d11637780c9` remains Blade-only for the real Operator path. Its only `@vite` is in the unused framework welcome template and is explicitly guarded by the presence of `public/build/manifest.json` or `public/hot`, with an inline fallback; the root route redirects directly to `/operator/today`. The deterministic check recognizes that exact optional guard, so this target skips npm. Any unguarded/future Vite or Mix reference fails closed when npm/build capability is unavailable.

Database migrations are not automatically reversed when code rolls back. Production migrations must therefore be backward-compatible with the immediately previous release.

## 5. External acceptance

After the deploy command passes:

```text
https://boatops.ayany.com/
-> /operator/today
-> /operator/login when unauthenticated
```

Then verify with the real Operator account:

- login succeeds;
- Today Operations loads;
- Calendar loads;
- Inquiry create/show loads;
- no unexpected cross-organization data appears;
- no unexplained application or scheduler error appears.

Do not create fake production orders for acceptance. Use the next genuine operation or read-only checks until a real order exists.

## 6. Definition of live

BoatOps is `LIVE` only when all are true:

```text
public HTTPS works
+ exact deployed Git SHA known
+ PostgreSQL production connection confirmed
+ scheduler active
+ backup exists
+ /up PASS
+ root/operator login boundary PASS
+ authenticated Operator smoke PASS
```

Only then update `.project/CURRENT_STATE.yaml` from `NOT_YET_VERIFIED_LIVE` to the verified production state and record the deployed SHA.
