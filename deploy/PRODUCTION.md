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

The committed Nginx, scheduler, and queue-worker files already target this layout.

## 2. Production `.env`

`/www/wwwroot/boatops.ayany.com/shared/.env` is server-local and must never be committed.

Required real-use values include:

```dotenv
APP_NAME=BoatOps
APP_ENV=production
APP_DEBUG=false
APP_URL=https://boatops.ayany.com
APP_LOCALE=zh_CN
APP_FALLBACK_LOCALE=en

DB_CONNECTION=pgsql
DB_HOST=<server-local value>
DB_PORT=5432
DB_DATABASE=<secret/local value>
DB_USERNAME=<secret/local value>
DB_PASSWORD=<secret/local value>

SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=database

BOATOPS_DEMO_SITE_ENABLED=false
BOATOPS_DEMO_SITE_MODE=disabled
BOATOPS_DEMO_SITE_ISOLATED_DATASET=false
BOATOPS_DEMO_SITE_ALLOW_PRODUCTION_SEED=false
```

Keep `APP_KEY` from the established runtime. Never regenerate it during a normal deploy.

## 3. One-time server wiring

If not already installed from the earlier BoatOps deployment:

- Nginx site: `deploy/nginx/boatops.ayany.com.conf`
- Scheduler: `deploy/cron/boatops-scheduler`
- Queue worker: `deploy/systemd/boatops-queue.service`
- Certificate renewal: `deploy/cron/boatops-cert-renew`

Before real use, verify:

```bash
systemctl is-active boatops-queue.service
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
5. installs locked Composer/npm dependencies and builds frontend assets;
6. runs production migrations;
7. atomically switches `current`;
8. restarts the queue worker;
9. verifies `/up`, `/ -> /operator/today`, and unauthenticated `/operator/today -> /operator/login`;
10. restores the previous code symlink automatically if smoke checks fail.

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
- no unexplained application/queue error appears.

Do not create fake production orders for acceptance. Use the next genuine operation or read-only checks until a real order exists.

## 6. Definition of live

BoatOps is `LIVE` only when all are true:

```text
public HTTPS works
+ exact deployed Git SHA known
+ PostgreSQL production connection confirmed
+ queue active
+ scheduler active
+ backup exists
+ /up PASS
+ root/operator login boundary PASS
+ authenticated Operator smoke PASS
```

Only then update `.project/CURRENT_STATE.yaml` from `NOT_YET_VERIFIED_LIVE` to the verified production state and record the deployed SHA.
