# BoatOps Worker Environment Contract

This file records the smallest evidenced environment and validation contract for a fresh BoatOps Worker. It does not authorize deployment or access to TEST/Production, and it is not a universal runtime image.

Status terms follow AI协同 V0.2:

- `OBSERVED` — directly present in committed authority/CI or executed in the onboarding slice.
- `INFERRED` — required by authoritative configuration but not executed in the stated environment.
- `UNVERIFIED` — plausible or available elsewhere, but not checked here.

## Authoritative evidence

Runtime and check requirements come from:

- `composer.json` and `composer.lock`;
- `package.json` and `package-lock.json`;
- `phpunit.xml`;
- `.github/workflows/ci.yml`;
- `tests/load/ci-postgres.sh` and its synthetic load scripts;
- the current project authority under `.project/`.

If these files change, re-discover the contract instead of treating this snapshot as permanent inventory.

## Required portable capabilities

| Capability | Classification | Evidenced requirement |
| --- | --- | --- |
| Git | `OBSERVED` | Required for safe baseline, diff, commit, and evidence. No minimum Git version is declared. Authentication stays in the Worker environment. |
| Bash | `OBSERVED` | GitHub CI uses Ubuntu shell steps; `tests/load/ci-postgres.sh` and the project check are Bash. Git Bash also passed the onboarding slice. |
| PHP | `OBSERVED` | `composer.json` requires `^8.4.1`; CI uses PHP 8.4. |
| Composer | `OBSERVED` | CI uses Composer 2 and the documented PHP check is `composer test`. |
| PHP extensions for portable checks | `OBSERVED` | CI provisions `curl`, `dom`, `fileinfo`, `mbstring`, `openssl`, `pdo_sqlite`, `sqlite3`, and `xml`. Composer may require additional standard PHP extensions recorded by the lock file. |
| Node.js / npm | `OBSERVED` | CI uses Node.js 22; `package-lock.json` makes npm the project package manager. |
| SQLite | `OBSERVED` | `phpunit.xml` runs the default PHP suite against an in-memory SQLite database. No external database or real data is needed. |
| PostgreSQL gate | `INFERRED` for a fresh portable Worker | CI separately uses PostgreSQL 17, `pdo_pgsql`, `psql`, and `btree_gist` creation privilege for concurrency validation. It is not part of the default portable check. |

No Dockerfile, Compose file, Dev Container, pnpm lockfile, or yarn lockfile exists. Docker, a Dev Container, pnpm, and yarn are therefore not BoatOps Worker requirements. The GitHub-hosted PostgreSQL service does not create a local Docker mandate.

`.gitattributes` owns LF normalization for tracked text. Do not change line-ending policy or work around it by disabling validation.

## Dependency bootstrap for checks

The check script does not install dependencies. From a fresh checkout, use the lockfile-backed CI commands:

```bash
composer install --no-interaction --prefer-dist --no-progress
npm ci --ignore-scripts
```

These commands require registry/network access. If a capability or dependency is missing, report a `CAPABILITY_BLOCKER` or bootstrap failure; do not silently install a different runtime, ignore platform requirements, or switch package managers.

`composer run setup` is the existing local-development path from `README.md`. It creates a local `.env`, key, database state, and frontend build, so it is not required for the portable repository check. Any local `.env` must be reviewed and must never target real data or TEST/Production.

## Single portable check

Run from the repository root:

```bash
bash scripts/check.sh
```

The wrapper only composes the existing documented checks, in order:

```text
composer test
vendor/bin/pint --test
npm run test:contract
npm run build
```

It does not change test semantics, install dependencies, start services, deploy, or connect to a remote database. Generated `public/build/` output is ignored by Git. A passing run must leave tracked and untracked repository state clean apart from the Worker's intentional task diff; verify with `git status --short`.

## CI-only and runtime-dependent checks

The current CI quality job additionally performs manifest validation, lockfile installs, direct PHPUnit, SQLite migration round-trip, dependency audits, and whitespace checks. The separate PostgreSQL job runs:

```bash
bash tests/load/ci-postgres.sh
```

That script is destructive to its selected database (`migrate:fresh`) and creates synthetic fixtures. Run it only against an explicitly isolated disposable PostgreSQL test database with the required service/process capabilities and cleanup evidence. Never point it at BoatOps TEST, Production, a real booking database, or real operational data.

PostgreSQL validation requires, as evidenced by CI/script inspection:

- PostgreSQL server access and a database user allowed to create `btree_gist`;
- PHP `pdo_pgsql`;
- `psql`, `curl`, `setsid`, process inspection tools, Bash, PHP, and Node.js;
- an isolated HTTP port and multi-worker PHP CLI server;
- synthetic test configuration only.

## Environment variable names

Values are environment-local and must never be copied into Git or handoffs.

Portable PHPUnit/quality configuration declares these names; Workers normally do not need to set them manually because `phpunit.xml` and CI own the test configuration:

```text
APP_ENV
APP_KEY
APP_MAINTENANCE_DRIVER
BCRYPT_ROUNDS
BROADCAST_CONNECTION
CACHE_STORE
DB_CONNECTION
DB_DATABASE
DB_URL
MAIL_MAILER
QUEUE_CONNECTION
SESSION_DRIVER
PULSE_ENABLED
TELESCOPE_ENABLED
NIGHTWATCH_ENABLED
NPM_CONFIG_IGNORE_SCRIPTS
```

The PostgreSQL concurrency gate additionally uses these names:

```text
APP_DEBUG
DB_HOST
DB_PORT
DB_USERNAME
DB_PASSWORD
BOATOPS_TOKEN
BOATOPS_BASE_URL
BOATOPS_HOST
BOATOPS_CONCURRENCY
BOATOPS_BOAT_ID
BOATOPS_TRIP_TEMPLATE_ID
BOATOPS_SLOT_OFFERING_ID
BOATOPS_SERVICE_DATE
BOATOPS_PHP
BOATOPS_APP_DIR
BOATOPS_SSH_HOST
PHP_CLI_SERVER_WORKERS
PGPASSWORD
RUNNER_TEMP
GITHUB_WORKSPACE
```

`.env.example` remains the authority for local application variable names and safety comments. Record names and capability/auth status only, never values.

## Observed onboarding profile

`BOATOPS-AI-COLLAB-001` directly observed and used this compatible portable profile:

- Windows `10.0.28120` host with Git Bash `5.3.9` and Windows PowerShell `5.1.28000.2630`;
- Git `2.54.0.windows.1`;
- PHP `8.4.22` with the portable extensions above supplied by Worker-local configuration;
- Composer `2.10.2`;
- Node.js `22.22.3` and npm `12.0.2`;
- SQLite-backed PHP suite, contract checks, Pint, and Vite build: `OBSERVED`;
- `pdo_pgsql`, `psql`, local PostgreSQL, and the PostgreSQL concurrency gate: `UNVERIFIED` in this Worker environment;
- TEST/Production access, deployment, and real-data validation: `NOT AUTHORIZED / NOT RUN`.

The exact observed versions are evidence, not new universal minimums. Any environment satisfying the committed constraints and checks may be used.

## Safe Worker sequence

```text
clean intended workspace
-> verify origin and fetch current base
-> record HEAD and origin/base
-> read AGENTS.md and BoatOps authority
-> verify the pinned AI协同 ref
-> verify git, bash, php, composer, node, npm and dependencies
-> run bash scripts/check.sh with synthetic/local SQLite data
-> verify Git identity and worktree state
-> return structured handoff evidence
```
