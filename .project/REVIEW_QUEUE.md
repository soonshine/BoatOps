# BoatOps Review Queue and Evidence Ledger

Last updated: 2026-08-10 11:44 Asia/Bangkok

This file has two jobs:

1. list active review blockers;
2. retain compact, immutable identities for review history.

It grants no authorization. Current machine state lives in `CURRENT_STATE.yaml`; allowed/forbidden actions, acceptance, and the next decision live in `CURRENT_GATE.md`.

## Active review blockers

| ID | Status | Review question |
| --- | --- | --- |
| `INV-P0-001` | `OPEN / PR12_MERGE_BLOCKER` | Does physical inventory authority survive Complete until `occupied_end`? |
| `INV-P0-002` | `OPEN / PR12_MERGE_BLOCKER` | Does a completed Booking retain required same-service-date compatibility? |

### Immutable causal evidence

Project-reset base `e0ee301e601c7d9db741e828990c477cf36a8d29`:

- `app/Http/Controllers/Api/Internal/V1/OperationsCommandController.php:419-425` sets Booking and allocation to `COMPLETED` immediately;
- `app/Services/SlotCatalog/SlotAvailabilityService.php:27-30` adjudicates compatibility from `ACTIVE` allocations;
- `app/Services/SlotCatalog/SlotCalendarReadModel.php:111-115` projects occupied intervals from `ACTIVE` allocations;
- `database/migrations/2026_08_01_000002_create_inventory_resource_tables.php:56-64` protects overlap only where allocation status is `ACTIVE`.

PR #12 reviewed head `d841418c24c90c30ceeb203e17150e55cb46d538`:

- `app/Application/Trips/CompleteTripAction.php:54-78` preserves the same immediate Booking/allocation completion;
- `tests/Feature/TripApplicationActionsTest.php:104-143,198-220` completes at 10:00 while `occupied_end` is 12:00 and treats that behavior as passing.

These coordinates prove that both P0 paths predate PR #12 and are preserved by it. The required repair outcome is defined only in `CURRENT_GATE.md`.

### PR #12 — Shared Trip Actions and Operator Trip Desk

Status: `DRAFT / PRIMARY_REVIEW_PASS_AT_D841418 / LATER_CROSS_INVARIANT_BLOCKED / DO_NOT_MERGE`

The recorded primary review accepted the bounded Trip Desk scope, including shared Trip Actions, Operator pages, lifecycle transitions, readiness invalidation, timestamp integrity, organization isolation, and the row-index fix. That acceptance does not cover the later-proven inventory invariants and cannot be reused for a new candidate head.

## Canonical evidence ledger

| Identity | Commit / run / artifact | Recorded status |
| --- | --- | --- |
| G1 reviewed code | `20978a169bbd52278b3bc4ab36e901a55c7e0b00` | COMPLETE / FROZEN |
| D1 product source | `f9503b598b174b7a6891fcde0d984514a3cd0fcd` | COMPLETE / FICTIONAL DEMO ONLY |
| D1 closure | `.project/D1_GOVERNANCE_CLOSURE.md` | COMPLETE / EVIDENCE CLOSED |
| Pilot scope-freeze main | `ae62d26f418ab841a67497387d03a904e33e9064` | HISTORICAL WP1-WP3 CONTRACT |
| Pilot scope contract | `docs/product/REAL_OPERATIONS_PILOT_MVP_SCOPE_FREEZE.md` | FROZEN HISTORY / NOT CURRENT NORTH STAR |
| WP1 PR | `#8` | COMPLETE / MERGED |
| WP1 reviewed head | `973e0456bf3c8672ae4ba03c61ac0a1c88cfd416` | PRIMARY REVIEW PASS |
| WP1 exact-head CI | Run `31310148095` | SUCCESS |
| WP1 merged main | `1114307d358e67d91ebcf742a26e9d7469209e67` | COMPLETE |
| WP1 post-merge main CI | Run `31310579582` | SUCCESS |
| WP2 PR | `#10` | COMPLETE / MERGED |
| WP2 reviewed head | `b340e7c84480c6bcc92ae62829cad0f7f0661fec` | PRIMARY REVIEW PASS |
| WP2 exact-head CI | Run `31317044622` | SUCCESS |
| WP2 merged main | `763d22bfc4ddaf0a84df1188d50f6d40b2fa72fc` | COMPLETE |
| WP2 post-merge main CI | Run `31346016491` | SUCCESS |
| Project-reset observed base | `e0ee301e601c7d9db741e828990c477cf36a8d29` | OBSERVED 2026-08-10 / IMMUTABLE BASE IDENTITY |
| WP3 PR | `#12` | DRAFT / NOT MERGED |
| WP3 initial head | `2248fb7` | PRIMARY REVIEW CHANGES REQUIRED |
| WP3 initial exact-head CI | Run `31348180203` | SUCCESS |
| WP3 row-index fix/reviewed head | `d841418c24c90c30ceeb203e17150e55cb46d538` | RECORDED PRIMARY REVIEW PASS |
| WP3 exact-head CI at `d841418` | Run `31350106392` | SUCCESS |
| WP3 later cross-invariant review | `INV-P0-001 + INV-P0-002` | BLOCKED |

## Interpretation boundaries

- WP1 and WP2 are merged history.
- WP3 remains a useful frozen implementation package in Draft PR #12; it is not a continuing roadmap unit.
- There is no WP4/WP5/WP6 queue. New review items arise only from a universal invariant, a real-use blocker, or observed operating pain.
- Deployment readiness, branch protection, formal licensing, Tag, and GitHub Release belong to later bounded Gates; they do not expand the current product.
