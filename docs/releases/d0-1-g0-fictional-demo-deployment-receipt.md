# BoatOps D0.1 Fictional Demo Deployment Receipt

Status: `D0.1_COMPLETE / DEPLOYMENT_ACCEPTED / HISTORICAL`

This file is the canonical GitHub summary of the D0.1 hardened fictional Demo deployment. It is an operational receipt, not a SemVer product release, Git Tag, GitHub Release, or production enablement record.

The original detailed receipt was recorded on historical branch `codex/boatops-d0-1-deployment-receipt` at commit `10fa260fce3ec8708f180ce016e723e6c7ea4180` under the filename `docs/releases/0.0.8-d0-1-deployment-receipt.md`. The `0.0.8` name was a historical candidate label only and is not adopted as a formal release version.

## Accepted identity

- Gate: `D0.1_G0_HARDENED_DEMO_DEPLOYMENT`
- Result: `COMPLETE / DEPLOYMENT_ACCEPTED`
- Release ID: `D0_1_20260808T140625Z`
- Source commit: `3826cb2c29aea4d2b92a90e04c14f8c218fbf45c`
- Dataset: isolated fictional Demo only
- Database: SQLite
- Production inventory master: no
- Real data: none
- Tag: none
- GitHub Release: none

## Runtime boundary

The accepted D0.1 runtime used:

- `BOATOPS_DEMO_SITE_MODE=public_read_only`
- an explicitly isolated fictional SQLite dataset;
- file cache;
- file sessions;
- synchronous queue;
- production seeder disabled;
- no production migration or production data import.

The public Demo boundary was verified to keep the application API closed and reject public non-GET writes.

## HTTP acceptance

Accepted public GET responses:

- `/up = 200`
- `/ = 200`
- `/demo = 200`
- `/demo/calendar = 200`
- `/demo/slots = 200`

The tested public non-GET matrix returned `405`, and the tested API matrix returned `404`, including representative requests made with a valid fictional credential.

## SQLite immutability

Accepted D0.1 SQLite evidence:

- main SHA256: `97d7738c866fa5df6062650da25e644258a4e5c60255c6e1ad83e7ea65632ab4`
- artifact-set SHA256: `8ddd602261a3c167cf718060cc6f4cddec071de07ba345d4590cca79e4eb03cb`
- canonical-row SHA256: `514b073dc1971da895454d5c7ec0bbe9603c9366ac8feb588eb137b640331fa0`
- integrity check: `ok`
- foreign-key violations: `0`
- row counts unchanged across the accepted HTTP/API verification

The tested public surface did not mutate application SQLite state.

## Backup and rollback proof

Retained backup evidence included:

- previous release archive SHA256: `91bb969d929fe0a44d52e5fed00446a200190f2f38189d3539ba6296b5c0fff1`
- configuration backup SHA256: `f015f47d44cc5ba94c0525260f4bc61350a64d8b3ffb9089ee68dfd8850230f1`
- scheduler backup SHA256: `549ca61e7e5e2ccf5b0910e9cb323cd94e57d475c6c407c9644aca178f815adc`
- SQLite backup SHA256: `97d7738c866fa5df6062650da25e644258a4e5c60255c6e1ad83e7ea65632ab4`

An actual historical rollback test was completed:

1. atomic switch from `D0_1_20260808T140625Z` to previous release `20260808T054205Z`;
2. previous `/up = 200` and `/demo = 200`;
3. atomic restoration to `D0_1_20260808T140625Z`;
4. restored `/up = 200` and `/demo = 200`.

Result: `PASS`.

## Relationship to later gates

D0.1 predates G1 deployment. It proved the hardened public fictional Demo boundary and rollback discipline. It does not prove:

- G1 Operator deployment;
- production PostgreSQL operation;
- real vessel/customer/order data;
- production operating rules;
- ChannelHub/OTA/payment integration;
- formal release readiness.

G1 and D1 are recorded separately in `.project/G1_GOVERNANCE_CLOSURE.md`, `.project/D1_GOVERNANCE_CLOSURE.md`, and `docs/releases/d1-g1-fictional-demo-deployment-receipt.md`.

## Final classification

- `D0.1_COMPLETE`
- `FICTIONAL_DATA_ONLY`
- `PUBLIC_READ_ONLY_DEMO`
- `ACTUAL_ROLLBACK_PROVEN`
- `NO_REAL_DATA`
- `NOT_PRODUCTION_MASTER`
- `NOT_TAGGED`
- `NOT_GITHUB_RELEASED`

This receipt supersedes the need to treat the historical `0.0.8` branch filename as a version identifier. The historical branch may be removed after repository-maintenance cleanup because the accepted D0.1 facts are now represented on the canonical governance line.