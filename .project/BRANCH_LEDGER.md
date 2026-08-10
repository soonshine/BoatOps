# BoatOps Branch Ledger

Updated: 2026-08-10 14:21 Asia/Bangkok

This file classifies known remote branches so an agent cannot infer authority from branch age, naming, or a commit that happens to be ahead of `main`.

## Rules

- `main` is the canonical integration branch.
- The D1 deployed product source remains `f9503b598b174b7a6891fcde0d984514a3cd0fcd`; later documentation/governance commits on `main` do not change the deployed D1 source identity.
- A remote branch is not authorized for merge merely because it is newer than `main`.
- `ABANDONED / SUPERSEDED` branches are never valid implementation or deployment baselines.
- Historical branch deletion must never delete evidence that has not first been represented on the canonical governance line.

## Governance alignment

D1 GitHub governance alignment was merged through PR #2 after exact-head CI success.

- PR: `#2 docs(governance): align D1 project state`
- PR head: `1e96f5e674d2c6a106eee231e2df8f0c9e3f9872`
- resulting `main` commit: `6bd9978efa79b574f7b309d9843c5fc1c6250057`
- resulting-main CI: `31305947677`, success
- business-code changes: none

Owner granted repository-governance cleanup authorization on 2026-08-09 after this alignment. That authorization does not extend to product-code changes, production data, server runtime, Tag, Release, or production enablement.

## Current canonical integration identity

Project Reset PR #13 was merged after the historical D1 governance alignment above.

- pre-reset base: `e0ee301e601c7d9db741e828990c477cf36a8d29`;
- Project Reset head: `aede9a495b1a6f98a218fd0d26d944b469f86980`;
- current canonical `main`: `32f817c4618d522b6d73253b3f1dcdc12018a78f`;
- post-main CI: Run `31360041676`, `Quality and contracts` SUCCESS and `PostgreSQL concurrency` SUCCESS;
- D1 deployed product source remains separately fixed at `f9503b598b174b7a6891fcde0d984514a3cd0fcd`.

## Known branches

| Branch | Recorded head / relation | Classification | Cleanup disposition |
| --- | --- | --- | --- |
| `main` | integration head `32f817c4618d522b6d73253b3f1dcdc12018a78f`; D1 deployed product source `f9503b5...` | CANONICAL | Preserve |
| `hermes/pilot-mvp-wp3-trip-desk` | `d841418c24c90c30ceeb203e17150e55cb46d538` | ACTIVE DRAFT / REPAIR REQUIRED / DO_NOT MERGE | Rebase only after this governance reconciliation merges; keep merge blocked |
| `agent/d1-governance-alignment` | PR #2 source branch | MERGED GOVERNANCE | Delete when repository tooling permits |
| `agent/d0-1-receipt-canonicalization` | D0.1 receipt canonicalization | CURRENT GOVERNANCE MAINTENANCE | Delete after merge |
| `codex/boatops-g1-post-merge-receipt` | `f9503b598b174b7a6891fcde0d984514a3cd0fcd` | HISTORICAL / ANCESTOR | Delete when repository tooling permits |
| `codex/boatops-g1-operator-mvp` | `2f9f5163cfcd37436cf5d8d65071691350a06b04` | HISTORICAL G1 / ANCESTOR | Delete when repository tooling permits |
| `codex/boatops-d0-1-deployment-receipt` | `10fa260fce3ec8708f180ce016e723e6c7ea4180` | HISTORICAL EVIDENCE-ONLY | Delete only after canonical D0.1 receipt is merged |
| `codex/boatops-d1-g1-demo-deployment` | `4e5c6c54b15e71272aeb1c7f609dabc7d71efded` | **ABANDONED / SUPERSEDED / DO_NOT_MERGE** | Delete when repository tooling permits |
| `codex/boatops-g0-project-alignment` | `547198e3a2e9e4c058803f0f58529bc997fa2542` | HISTORICAL / ANCESTOR | Delete when repository tooling permits |
| `codex/boatops-g0-read-only-isolation-hardening` | `ead79da1a7cc39be0d18ac26d5388689b131fc13` | HISTORICAL / ANCESTOR | Delete when repository tooling permits |
| `codex/boatops-v0.0.6-gate-b1` | `be4e642f0db3460802d7bc6b6f3cc46c896fa6d8` | HISTORICAL / ANCESTOR | Delete when repository tooling permits |
| `codex/boatops-v0.0.7-public-demo` | `c10f3a2eb2769a2f30f346906131b3c07c95e111` | HISTORICAL / ANCESTOR | Delete when repository tooling permits |

## Superseded D1 experiment

The branch `codex/boatops-d1-g1-demo-deployment` contains an experimental D1 implementation that modified source to add a dedicated operator-demo mode. Its CI did not become the accepted D1 deployment path.

The accepted D1 deployment instead used exact source:

`f9503b598b174b7a6891fcde0d984514a3cd0fcd`

with **no source change**, two runtime directories, a public `public_read_only` runtime, and a private loopback-only Operator runtime sharing the same fictional D1 SQLite dataset.

Therefore:

- never merge the experimental D1 branch;
- do not repair it for the purpose of completing D1;
- do not cite its later commits as D1 deployed source;
- do not infer that `isolated_operator_demo` is an accepted product requirement;
- use `.project/D1_GOVERNANCE_CLOSURE.md` as the D1 governance record.

## Repository-maintenance limitation

The connected GitHub toolset used for this governance pass does not expose remote branch deletion or branch-protection/ruleset mutation. Therefore those platform mutations are not represented as completed merely because Owner authorization exists.

Until the platform actions are actually executed:

- branch classifications in this ledger remain authoritative;
- `main` must be treated as unprotected at the GitHub platform level;
- all agents must continue to obey `.project/AGENT_RULES.md` and require CI plus reviewer/Owner authorization before merge.
