# BoatOps Production Runbook

BoatOps uses one real operating runtime:

`https://boatops.ayany.com/`

This runbook intentionally stays small. It exists only to make a production change repeatable, observable, and reversible at the code level.

## 1. Permanent runtime contract

- GitHub `main` is the code source of truth.
- Production PostgreSQL is the operational-data source of truth.
- Deploy an exact 40-character Git SHA, never an unpinned branch working tree.
- No historical Git SHA is a permanent approved target: every deployment re-verifies the candidate exact SHA, the checked-out release content, and the resulting runtime capability.
- Do not edit application source directly on the server.
- A permanent TEST/staging environment is not required.
- Synthetic/destructive tests must never target production data.

Deployment privilege boundary (Issue #49):

- Privileged filesystem / ownership / atomic-symlink / mutex work stays under root.
- Repository-controlled `composer install`, `npm ci`, `npm run build`, and `php artisan ...` execute as a non-root deploy/web user (`BOATOPS_WEB_USER`, default `www`), never as root.
- Every deployment holds a single-instance mutex (`flock`) so concurrent deployments cannot race.
- The release model remains exact-SHA checkout into an immutable release directory with an atomic `current -> releases/<release-id>` switch and automatic rollback on smoke failure.

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

Non-root boundary requirements on the host:

- a non-root deploy/web user exists (`BOATOPS_WEB_USER`, default `www`; `BOATOPS_WEB_GROUP`, default `www`);
- `runuser` or `su` is available (the script fails closed otherwise);
- `git`, `flock`, `curl`, and `composer` are available to the script's environment, and `BOATOPS_PHP` (default `/www/server/php/84/bin/php`) is executable;
- the shared `.env` is readable by the deploy user.
- the deploy script marks each release checkout (`git safe.directory`) for the deploy user so the non-root composer/artisan flow can probe the repository without an ownership error. The deploy script fixes group read access (`root:<web-group>`, mode `640`) when needed; it never widens world access.

The queue worker is not a current deployment or live gate. The deploy verifies the candidate release's own content rather than trusting a pinned historical target: a release whose checked-out content contains no `ShouldQueue` implementation, queued Job/Listener, `dispatch()` path, or Queue/Bus facade call does not need the queue worker. The Operator booking path calls `ConfirmBookingAction` synchronously (`app/Http/Controllers/Operator/BookingWorkflowController.php:36`), and the required scheduler calls `ExpireDueHolds` synchronously (`app/Console/Commands/ExpireHolds.php:17`). Application actions insert outbox rows inside their database transactions (for example, `app/Application/Holds/ExpireDueHoldAction.php:64`), but there is no current in-repository queue consumer. `deploy/systemd/boatops-queue.service` remains only as a future wiring reference until a concrete queued workload exists in a candidate release.

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
2. acquires the single-instance deployment mutex (stable lock file, default `$ROOT/.deploy.lock`, override `BOATOPS_LOCK_FILE`) and fails pre-flight when another deployment holds it;
3. creates a new immutable release directory;
4. checks out the exact requested Git SHA (full 40-character SHA and verified checkout HEAD);
5. reuses shared `.env` and `storage`;
6. prepares the release ownership for the non-root deploy user and installs the locked Composer dependencies as that user;
7. inspects the checked-out release and builds locked frontend dependencies only when Vite/Mix assets are required, again as the non-root deploy user;
8. runs production migrations as the non-root deploy user;
9. atomically switches `current`;
10. verifies `/up`, `/ -> /operator/today`, and unauthenticated `/operator/today -> /operator/login`;
11. restores the previous code symlink automatically if smoke checks fail.

### D. Privilege boundary audit

The deploy script must keep root scoped to privileged work only:

| Run as root | Run as non-root deploy/web user |
| --- | --- |
| mutex lock acquisition (`flock`) | `composer install` (locked deps) |
| release directory creation / git checkout | `npm ci --ignore-scripts` |
| env / storage symlinks | `npm run build` |
| `chown` ownership fixes (shared storage, release write paths, env group read) | `php artisan optimize:clear` |
| atomic `current` switch and rollback | `php artisan migrate --force` |
| smoke checks | `php artisan config:cache` |
| | `php artisan view:cache` |
| | `php artisan migrate:status` |

Repository-controlled commands are routed through one helper (`run_repository_command`) using the minimal primitives available on the host (`runuser`, with `su` as fallback). The boundary fails closed when the deploy user is missing, is uid 0, or no privilege-drop primitive exists.

The release-content check treats `public/build/manifest.json`, `public/mix-manifest.json`, an unguarded Blade `@vite`/`Vite::asset`, or a Blade `mix()` call as requiring frontend assets. In that case npm, `package.json`, and `package-lock.json` are mandatory; the deploy runs `npm ci --ignore-scripts`, `npm run build`, and fails closed unless a Vite/Mix manifest exists afterward.

The frontend decision is deterministic from the candidate release's own content, never from a pinned historical SHA. The current production release is Blade-only for the real Operator path: its only `@vite` is in the unused framework welcome template and is explicitly guarded by the presence of `public/build/manifest.json` or `public/hot`, with an inline fallback; the root route redirects directly to `/operator/today`. The deterministic check recognizes that exact optional guard, so a release with that content skips npm. Any unguarded/future Vite or Mix reference fails closed when npm/build capability is unavailable. Every deployment re-verifies the candidate exact SHA, the checked-out release content, and the resulting runtime capability; no historical Git SHA is a permanent approved target.

Database migrations are not automatically reversed when code rolls back. Production migrations must therefore be backward-compatible with the immediately previous release.

### E. Pre-deployment rehearsal / dry run

Before the next real code deployment, run a bounded production-host rehearsal against the exact SHA currently in production (same application code identity, so no unauthorized application change is deployed):

```bash
sudo bash deploy/scripts/deploy-production.sh <current-production-sha> --backup-confirmed --rehearsal
```

Rehearsal mode:

- still requires `--backup-confirmed`, so the complete acknowledgement flow is proven;
- acquires the same single-instance mutex as a real deployment;
- checks out the exact requested SHA into an immutable `-rehearsal` release directory;
- executes `composer install`, the conditional npm build path, and the `artisan` capability commands as the non-root deploy user;
- runs only read-only database checks (`artisan migrate:status`); it skips `artisan migrate --force`;
- never switches `current`, never runs smoke/rollback, and never changes the running application.

The rehearsal leaves its immutable release directory in place (not referenced by `current`) and records a `REHEARSAL PASS` summary. Record the full rehearsal evidence in the owning Issue.
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
