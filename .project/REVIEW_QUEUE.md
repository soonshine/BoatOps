# BoatOps Historical Review Ledger

Status: `HISTORICAL_ONLY`

This file is no longer an active queue or current-state authority.

BoatOps moved to the direct real-use production loop. Current authority is:

1. `.project/PROJECT_CHARTER.md` — mission, scope, principles, invariants.
2. `.project/CURRENT_STATE.yaml` — current runtime and project facts.
3. `.project/CURRENT_GATE.md` — immediate allowed / forbidden / next action.
4. Owning GitHub Issues / PRs — bounded task contracts and durable execution evidence.
5. Git history — historical gates, TEST-era state, prior review queues, and superseded decisions.

Do not reconstruct the current task or deployment state from the historical contents previously stored in this file.

The pre-production / TEST-era review ledger remains available in Git history before the 2026-08-23 SSOT cleanup. No separate replacement queue is created.

Current operational direction:

```text
WAIT_FOR_NEXT_GENUINE_OPERATION
-> execute through existing production Operator Web
-> observe a proven gap
-> make the smallest bounded change only when required
```
