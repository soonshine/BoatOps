# BoatOps Branch Ledger

Updated: 2026-08-13 12:29 Asia/Bangkok

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

Project Reset PR #13 and Core Safety reconciliation PR #14 were followed by the separately reviewed and authorized merge of PR #12. Deployment Readiness governance PRs #15 and #16 were then reviewed and merged. PR #18 merged the bounded Real Pilot provisioning line into `main`; PR #19 has now merged CAL-UX-001.

CAL-UX-001 integration is complete. Its former candidate branch is historical and is not deployment authority.

- Project Reset resulting main: `32f817c4618d522b6d73253b3f1dcdc12018a78f`;
- Core Safety reconciliation resulting main: `1f300c071f9066ff83e102798999e0852cedf7fa`;
- PR #12 candidate head: `f3f3a2adee5a76e62f70cc41cef111aa9feb0178`;
- PR #12 merge commit/resulting main: `5f1424f189865ca412577510c1ada450e838da18`;
- merge parents: `1f300c071f9066ff83e102798999e0852cedf7fa` and `f3f3a2adee5a76e62f70cc41cef111aa9feb0178`;
- PR #12 post-main CI: Run `31448746777`, overall SUCCESS, `Quality and contracts` SUCCESS, and `PostgreSQL concurrency` SUCCESS;
- PR #15 reviewed head: `65bbb8b03d370332b8afd35f71dcc64b6cdab02d`;
- historical PR #15 merge commit: `1864469b1b159442ecc598c919faa75431dca778`;
- PR #15 merge parents: `5f1424f189865ca412577510c1ada450e838da18` and `65bbb8b03d370332b8afd35f71dcc64b6cdab02d`;
- PR #15 post-main CI: Run `31454471881`, overall SUCCESS, `Quality and contracts` SUCCESS, and `PostgreSQL concurrency` SUCCESS;
- PR #16 reviewed head: `b3c30ae2329fd99e511d6a6fb24717f908f5d82a`;
- PR #16 merge commit/historical canonical main: `36fe230a12e3d24a7bcb8c0333f3ec15012c029e`;
- PR #16 merge parents: `1864469b1b159442ecc598c919faa75431dca778` and `b3c30ae2329fd99e511d6a6fb24717f908f5d82a`;
- PR #18 former branch: `codex/test-runtime-vertical-slice`;
- PR #18 immutable implementation candidate: `987eba04a1dc9073be6c02631792808debc35635`;
- PR #18 reviewed head: `71d2da4cfaa28c9fe8ecc31d7925d004c89e9236`;
- PR #18 merge commit and phase-II authoring baseline: `00a029c9a3dcd2122a958514e845334d0a295ac9`;
- PR #18 status: `MERGED / CLOSED`; its immutable candidate is included in `main` ancestry;
- PR #19 branch: `codex/cal-ux-001` at `fe05d92a9534b8e2dac2f4b6af4c6161ec4c4afa`;
- PR #19 merge base: `82494d85bc2d918359d610932ae01869a29839e8`;
- PR #19 merge commit and verified authoring baseline: `77db16f16617ddcbb09ebf66d83a65a0c97695e5`;
- PR #19 status: `MERGED / CLOSED / INTEGRATION COMPLETE / NOT_DEPLOYED`;
- PR #19 Owner merge authorization: `GRANTED_AND_CONSUMED`;
- live `main` and active PR branch heads are resolved from GitHub refs at Gate time;
- D1 deployed product source remains separately fixed at `f9503b598b174b7a6891fcde0d984514a3cd0fcd` and remains fictional Demo history only.

## Live branch identity invariant

`LIVE_BRANCH_REF_IS_EXTERNAL_STATE`

A governance document may retain historical commit identities, reviewed candidate identities, and the exact baseline against which it was authored. It must not attempt to embed the unknown future SHA produced by merging that same document. Resolve `refs/heads/main` and any active candidate branch from GitHub at review, merge, Deployment, Cutover, and Release Gate time.

## Known branches

| Branch | Identity rule / recorded history | Classification | Cleanup disposition |
| --- | --- | --- | --- |
| `main` | Authoring baseline `77db16f16617ddcbb09ebf66d83a65a0c97695e5`; live head resolved from GitHub `refs/heads/main`; PR #18, Real Pilot candidate, PR #19, and CAL-UX-001 implementation ancestry included; D1 deployed source remains separate history | CANONICAL / PR #18 AND PR #19 INCLUDED / REAL PILOT AND CAL-UX-001 ANCESTRY INCLUDED | Preserve |
| `codex/test-runtime-vertical-slice` | PR #18 reviewed head `71d2da4cfaa28c9fe8ecc31d7925d004c89e9236`; immutable candidate `987eba04a1dc9073be6c02631792808debc35635`; merged as `00a029c9...` | MERGED / HISTORICAL REAL PILOT BRANCH / NO LONGER ACTIVE IMPLEMENTATION AUTHORITY | Preserve for evidence; do not use as active authority |
| `codex/cal-ux-001` | PR #19 reviewed head `fe05d92a9534b8e2dac2f4b6af4c6161ec4c4afa`; merged as `77db16f16617ddcbb09ebf66d83a65a0c97695e5`; UI and existing read path only; inventory authority unchanged | MERGED / HISTORICAL CAL-UX-001 BRANCH / NO LONGER ACTIVE IMPLEMENTATION AUTHORITY / NOT DEPLOYMENT AUTHORITY | Preserve for evidence; do not use as active authority |
| `governance/deployment-readiness-closure-plan` | Reviewed head `b3c30ae2329fd99e511d6a6fb24717f908f5d82a`; merged by PR #16 into `36fe230a12e3d24a7bcb8c0333f3ec15012c029e` | MERGED / HISTORICAL GOVERNANCE | Preserve for evidence; do not reuse as active authority |
| `governance/post-pr12-deployment-readiness` | `65bbb8b03d370332b8afd35f71dcc64b6cdab02d`; merged by PR #15 into `1864469b...` | MERGED / HISTORICAL GOVERNANCE | Preserve for evidence; do not reuse as active authority |
| `hermes/pilot-mvp-wp3-trip-desk` | `f3f3a2adee5a76e62f70cc41cef111aa9feb0178`; merged by PR #12 into `5f1424f...` | MERGED / HISTORICAL CORE SAFETY CANDIDATE | Preserve for evidence; do not reuse as active implementation authority |
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

Live GitHub public metadata observed on 2026-08-11 reports:

- `main.protected = false`;
- repository rulesets count = `0`;
- Issue #4, `Governance: protect main and remove superseded branches`, remains OPEN.

This task does not authorize branch deletion or branch-protection/ruleset mutation. Until those platform actions are separately authorized, executed, and verified:

- branch classifications in this ledger remain authoritative;
- `main` must be treated as unprotected at the GitHub platform level;
- DR16 remains `PARALLEL_BEFORE_CUTOVER / NOT_CURRENT_REAL_PILOT_BLOCKER`;
- all agents must continue to obey `.project/AGENT_RULES.md` and require both CI jobs plus reviewer/Owner authorization before merge.
