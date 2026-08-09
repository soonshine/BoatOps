# BoatOps D1 Governance Closure

Closure recorded: 2026-08-09 Asia/Bangkok

Status: `D1_COMPLETE / EVIDENCE_CLOSED`

This document is a non-secret Git governance summary of the accepted D1 operational evidence. It is not a Tag, GitHub Release, production launch, or replacement for the retained server-side evidence artifacts.

## 1. Gate decision

D1 is accepted as complete for its authorized purpose:

**Deploy and validate the G1 Operator workflow in an isolated fictional Demo without real data and without enabling production.**

Reviewer gate decision: `APPROVED / COMPLETE`.

Next product gate: `UNDEFINED`.

## 2. Exact source and CI

- Repository: `soonshine/BoatOps`
- Source: `f9503b598b174b7a6891fcde0d984514a3cd0fcd`
- D1 source changes: `NONE`
- GitHub Actions run: `31294685662` — `SUCCESS`
- Quality/contracts job: `93197737212` — `SUCCESS`
- PostgreSQL concurrency job: `93197737241` — `SUCCESS`
- Tags: `0`
- GitHub Releases: `0`

## 3. Deployment identity

- Release: `D1_G1_20260809T045741Z`
- Public runtime: `public_read_only`
- Public current target: `/www/wwwroot/boatops.ayany.com/releases/D1_G1_20260809T045741Z/public-runtime`
- Private Operator bind: `127.0.0.1:18082` only
- Operator service: `active/enabled` at final acceptance
- Dataset: `FICTIONAL_ONLY`
- Real data: `NONE`
- Production enabled: `NO`

The public and private runtimes used exact `f950...` source and the same isolated fictional D1 SQLite. The private Operator surface was intentionally reachable only through an SSH tunnel to loopback.

## 4. D1 SQLite

Authoritative D1 SQLite SHA256:

`62299484458b8e6f63ca7f457ae713d6d3812e31b654ada8847a6d302596b08f`

Validation:

- integrity_check = `ok`
- foreign_key violations = `0`

Final row counts:

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

Synthetic Operator:

`operator.d1.synthetic@fictional.invalid`

No secret is recorded here.

## 5. Workflow evidence

Pre-switch workflow:

`D1-PREFLIGHT-EFC8506040C3`

Live workflow:

`D1-LIVE-43C5C393E126`

Accepted final state:

- booking 6 = `CANCELLED`
- allocation 9 = `CANCELLED`
- trip 6 = `CANCELLED`
- block 3 = `RELEASED`
- allocation 10 = `RELEASED`
- rate snapshot = `NONE`

The earlier review expectation that booking allocation 9 should be `RELEASED` was a specification error. The accepted persisted lifecycle state is `CANCELLED`; no database mutation was made to satisfy the evidence closure.

## 6. Rollback hash reconciliation

Authoritative rollback script SHA256:

`0f785385bd57c8165470f436e71009a11e4971b2687a48d1da36e5e2bacad11a`

A stale expected value:

`2a5a4f93306d0a360299f34085f6ddf626fbfc3339e19225e25d185b3c4febc9`

was found only in final JSON expected fields. Read-only reconciliation established that retained pre-switch hardening evidence and the checksum manifest backed the `0f785...` revision, and the successful actual rollback occurred after that hardened revision existed. The `2a5...` value is therefore classified `STALE_ORPHANED_EXPECTED_VALUE`, not an accepted script revision.

## 7. Actual rollback and restore

D0.1 identity:

- Release: `D0_1_20260808T140625Z`
- Source: `3826cb2c29aea4d2b92a90e04c14f8c218fbf45c`
- SQLite SHA256: `97d7738c866fa5df6062650da25e644258a4e5c60255c6e1ad83e7ea65632ab4`

Accepted evidence:

- D1 -> D0 actual rollback = `PASS`
- rollback exit = `0`
- D0 source/database verified
- D0 public probes passed
- D1 SQLite preserved
- D0 -> D1 actual restore = `PASS`
- D1 public probes passed
- private Operator returned active/enabled
- final bind remained loopback-only

## 8. Final evidence closure

Hermes final result: `D1_EVIDENCE_CLOSED`.

Only three server-side evidence artifacts were modified during closure:

- `D1_RELEASE_METADATA.json`
  - SHA256 `88f6d500acef3bc24761dcd4467cdc46a4ebce495a98eb7c5d8842b6339b5f4d`
- `evidence/D1_FINAL_DEPLOYMENT_RECEIPT.json`
  - SHA256 `cac020f04f719c0c6885e3ea9abdb67eb15d1b913729d8878c50564a33aaa5c1`
- `SHA256SUMS`
  - SHA256 `1ffc9cbded300c166cefd7cae96d7fdbc22e1c4bcd2e2e346a0d02476200c18b`

Checksum result: `25/25 PASS`.

Closure assertions:

- `NO_SOURCE_CHANGE`
- `NO_DATABASE_MUTATION`
- `NO_RUNTIME_CHANGE`
- `NO_REAL_DATA`
- `NOT_TAGGED`
- `NOT_RELEASED`
- `G2_NOT_STARTED`

## 9. Product positioning

D1 does not establish Ayany ownership of the current vessels.

BoatOps is a reusable organization-scoped product. The current two-vessel scenario and deployment hostname are validation/deployment facts only. Vessel ownership, operating rights, commercial terms, schedules, prices, buffers, and real operator identities remain deployment-specific and must be separately frozen for any real deployment.

## 10. Final boundary

- D1 live database is SQLite Demo only.
- PostgreSQL concurrency evidence is CI evidence, not D1 live PostgreSQL evidence.
- Demo TTL and operating times are fictional validation settings, not production policy.
- ChannelHub remains separate and not enabled.
- Real-data migration and production enablement remain separately unauthorized.

`D1_COMPLETE / FICTIONAL_DEMO_ONLY / PRODUCTION_NOT_ENABLED / NEXT_GATE_UNDEFINED`
