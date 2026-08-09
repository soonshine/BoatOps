# BoatOps Branch Ledger

Updated: 2026-08-09 16:17 Asia/Bangkok

This file classifies known remote branches so an agent cannot infer authority from branch age, naming, or a commit that happens to be ahead of `main`.

## Rules

- `main` is the default integration branch.
- A remote branch is not authorized for merge merely because it is newer than `main`.
- `ABANDONED / SUPERSEDED` branches are never valid implementation or deployment baselines.
- Branch deletion is a separate repository-maintenance action; this ledger does not itself authorize deletion.

## Current branches

| Branch | Recorded head | Classification | Action |
| --- | --- | --- | --- |
| `main` | `f9503b598b174b7a6891fcde0d984514a3cd0fcd` at alignment start | D1 PRODUCT SOURCE / GOVERNANCE BASELINE | Preserve |
| `codex/boatops-g1-post-merge-receipt` | `f9503b598b174b7a6891fcde0d984514a3cd0fcd` | HISTORICAL / SAME HEAD AS MAIN AT ALIGNMENT START | Cleanup candidate after alignment |
| `codex/boatops-g1-operator-mvp` | `2f9f5163cfcd37436cf5d8d65071691350a06b04` | HISTORICAL G1 | Cleanup candidate after alignment |
| `codex/boatops-d0-1-deployment-receipt` | `10fa260fce3ec8708f180ce016e723e6c7ea4180` | HISTORICAL EVIDENCE-ONLY | Preserve until evidence is captured in main governance, then cleanup candidate |
| `codex/boatops-d1-g1-demo-deployment` | `4e5c6c54b15e71272aeb1c7f609dabc7d71efded` | **ABANDONED / SUPERSEDED / DO_NOT_MERGE** | Delete only under explicit cleanup authorization |
| `codex/boatops-g0-project-alignment` | `547198e3a2e9e4c058803f0f58529bc997fa2542` | HISTORICAL | Cleanup candidate |
| `codex/boatops-g0-read-only-isolation-hardening` | `ead79da1a7cc39be0d18ac26d5388689b131fc13` | HISTORICAL | Cleanup candidate |
| `codex/boatops-v0.0.6-gate-b1` | `be4e642f0db3460802d7bc6b6f3cc46c896fa6d8` | HISTORICAL | Cleanup candidate |
| `codex/boatops-v0.0.7-public-demo` | `c10f3a2eb2769a2f30f346906131b3c07c95e111` | HISTORICAL | Cleanup candidate |

## Superseded D1 experiment

The branch `codex/boatops-d1-g1-demo-deployment` contains an experimental D1 implementation that modified source to add a dedicated operator-demo mode. Its CI did not become the accepted D1 deployment path.

The accepted D1 deployment instead used:

`f9503b598b174b7a6891fcde0d984514a3cd0fcd`

with **no source change**, two isolated runtime directories, a public `public_read_only` runtime, and a private loopback-only Operator runtime sharing the same fictional D1 SQLite dataset.

Therefore:

- do not merge the experimental D1 branch;
- do not repair it for the purpose of completing D1;
- do not cite its later commits as D1 deployed source;
- do not infer that `isolated_operator_demo` is an accepted product requirement;
- use `.project/D1_GOVERNANCE_CLOSURE.md` as the D1 governance record.

## Cleanup gate

After this governance alignment is merged and exact-head CI passes, the Owner may separately authorize deletion of historical remote branches. Branch cleanup must not delete `main`, Tags, Releases, or evidence files and must not modify product code.
