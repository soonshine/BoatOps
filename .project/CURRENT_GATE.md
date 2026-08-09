# Current Gate: D1 G1 Fictional Demo Deployment Closure

Status: `COMPLETE`

Reviewer decision: `APPROVED`

Next product gate: `UNDEFINED / NOT_AUTHORIZED`

## Objective

Freeze the accepted D1 fictional Demo deployment and its evidence in project governance without reclassifying it as production, without changing the BoatOps business source, and without inferring a new product gate.

## Product positioning correction

BoatOps is a reusable, organization-scoped vessel inventory and operations product. It is not an Ayany-specific system.

- `boatops.ayany.com` is a deployment hostname, not proof of vessel ownership.
- The current two-vessel Plan A / Plan B scenario is a reference/validation operating scenario.
- Vessel ownership, operating rights, schedules, buffers, prices, commissions, weather rules, and operator identities are deployment-specific data.
- No current vessel is asserted by this gate to be owned by Ayany.
- Ayany must not be hard-coded as tenant, vessel owner, or required integration.

## Frozen Git identity

- Repository: `soonshine/BoatOps`
- D1 product source: `f9503b598b174b7a6891fcde0d984514a3cd0fcd`
- Source change for D1 deployment: `NO`
- Exact source CI: GitHub Actions `31294685662` — success
- Quality/contracts job `93197737212` — success
- PostgreSQL concurrency job `93197737241` — success
- Tags: `0`
- GitHub Releases: `0`

The D1 deployment used the exact reviewed source above. D1-specific deployment behavior was achieved through isolated runtime configuration and separate runtime directories, not a D1 source branch.

## D1 deployment identity

- Release: `D1_G1_20260809T045741Z`
- Dataset: `FICTIONAL_ONLY`
- Production enabled: `NO`
- Real data: `NO`
- Public runtime: `public_read_only`
- Public current target: `/www/wwwroot/boatops.ayany.com/releases/D1_G1_20260809T045741Z/public-runtime`
- Private Operator access: SSH tunnel to loopback-only `127.0.0.1:18082`
- Private Operator service: `active/enabled`
- Public/wildcard 18082 bind: `NONE`

## Database evidence

D1 live uses SQLite for fictional Demo validation. This is not a production PostgreSQL deployment.

- D1 SQLite SHA256: `62299484458b8e6f63ca7f457ae713d6d3812e31b654ada8847a6d302596b08f`
- integrity_check: `ok`
- foreign-key violations: `0`

Accepted final row counts:

```text
organizations=1
users=1
inquiries=2
holds=3
bookings=6
trips=6
blocks=3
allocations=10
audit_logs=24
idempotency_keys=19
outbox_events=12
rate_snapshots=0
expenses=0
```

Synthetic Operator identifier:

`operator.d1.synthetic@fictional.invalid`

No password, password hash, APP_KEY, cookie, session secret, CSRF token, or credential is recorded in Git governance.

## Workflow acceptance

Pre-switch reference:

`D1-PREFLIGHT-EFC8506040C3`

Live reference:

`D1-LIVE-43C5C393E126`

Accepted final live state:

- booking `6` = `CANCELLED`
- booking allocation `9` = `CANCELLED`
- trip `6` = `CANCELLED`
- block `3` = `RELEASED`
- block allocation `10` = `RELEASED`
- rate snapshot = `NONE`

The persisted `CANCELLED` allocation state is accepted; the cancelled booking no longer represents active occupied inventory. It must not be rewritten to `RELEASED` merely to satisfy an evidence expectation.

## Rollback and restore

D0.1 frozen identity:

- Release: `D0_1_20260808T140625Z`
- Source: `3826cb2c29aea4d2b92a90e04c14f8c218fbf45c`
- SQLite SHA256: `97d7738c866fa5df6062650da25e644258a4e5c60255c6e1ad83e7ea65632ab4`

Authoritative D1 rollback script SHA256:

`0f785385bd57c8165470f436e71009a11e4971b2687a48d1da36e5e2bacad11a`

The previously expected `2a5a4f93306d0a360299f34085f6ddf626fbfc3339e19225e25d185b3c4febc9` is classified `STALE_ORPHANED_EXPECTED_VALUE`; retained evidence did not establish it as an actual rollback script revision.

Accepted rollback evidence:

- actual D1 -> D0 rollback: `PASS`
- rollback exit: `0`
- exact D0 source/database: verified
- D1 SQLite preserved
- D0 public probes passed
- actual D0 -> D1 restore: `PASS`
- D1 public probes passed
- Operator returned active/enabled on loopback only

## Evidence closure

Final server-side D1 evidence closure status: `D1_EVIDENCE_CLOSED`

Final evidence artifact hashes:

- `D1_RELEASE_METADATA.json`: `88f6d500acef3bc24761dcd4467cdc46a4ebce495a98eb7c5d8842b6339b5f4d`
- `evidence/D1_FINAL_DEPLOYMENT_RECEIPT.json`: `cac020f04f719c0c6885e3ea9abdb67eb15d1b913729d8878c50564a33aaa5c1`
- `SHA256SUMS`: `1ffc9cbded300c166cefd7cae96d7fdbc22e1c4bcd2e2e346a0d02476200c18b`
- checksum verification: `25/25 PASS`

The stale `2a5...` value may remain only as a non-authoritative reconciliation fact; `0f785...` is authoritative.

## Preserved G1 P2 findings

1. Audit rows lack an explicit request/idempotency correlation field.
2. Coarse organization-level write locking may limit same-organization throughput.
3. Operator inquiry/block/audit listings remain unpaginated MVP surfaces.

These are not D1 blockers.

## P2 / deployment caveats

- D1 live uses SQLite.
- PostgreSQL concurrency is validated in CI, not by the D1 live runtime.
- `inventory.hold_ttl_minutes=30` and other operating-time values used in Demo validation are fictional scenario settings, not production policy.
- The private Operator SSH tunnel is intentional for this fictional validation deployment.
- D1 is not a Tag, GitHub Release, or production launch.

## Authorization boundary after D1

- business_code_change_authorized=false
- merge_authorized=false
- deployment_authorized=false
- tag_authorized=false
- release_authorized=false
- production_enablement_authorized=false
- production_data_authorized=false
- google_sheet_migration_authorized=false
- channelhub_authorized=false
- ota_authorized=false

No gate named `G2A`, `G2B`, or similar is currently defined or authorized.

## Next decision

`DEFINE_NEXT_PRODUCT_GATE`

Before new business-code work, the Owner and reviewer must select the next real business outcome, inventory existing source capabilities to avoid duplicate implementation, and write explicit acceptance criteria.

`D1_COMPLETE / FICTIONAL_DEMO_ONLY / PRODUCTION_NOT_ENABLED / NOT_TAGGED / NOT_RELEASED / NEXT_GATE_UNDEFINED`
