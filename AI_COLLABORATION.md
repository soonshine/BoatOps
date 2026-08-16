# BoatOps AI Collaboration Reference

Repository: [`soonshine/ai-collaboration`](https://github.com/soonshine/ai-collaboration)

Approved ref: 1719d2a24dfc0cbcac5f5820145e207892b4527f

Protocol: `AI协同 V0.2`

This immutable commit is the approved cross-project Worker/environment process contract for BoatOps. A moving branch, later commit, copied chat, or Worker memory is not a substitute.

## Authority boundary

```text
BoatOps main + .project
= BoatOps business / inventory / safety / operations / deployment / Gate Truth

ai-collaboration approved ref
= cross-project Worker / Task / Handoff / environment process contract
```

BoatOps project-local authority wins on BoatOps domain, safety, operations, schema, data, deployment, and authorization. Stop and report ambiguity rather than inventing an override or expanding scope. The shared protocol grants no Merge, Deployment, TEST/Production access, real-data, Cutover, Tag, or Release authority.

## V0.2 read set

Read these files from the approved ref, in addition to that repository's `AGENTS.md` and `README.md`:

1. [`EXECUTION_DOCTRINE.md`](https://github.com/soonshine/ai-collaboration/blob/1719d2a24dfc0cbcac5f5820145e207892b4527f/EXECUTION_DOCTRINE.md)
2. [`ENVIRONMENT_CONTRACT.md`](https://github.com/soonshine/ai-collaboration/blob/1719d2a24dfc0cbcac5f5820145e207892b4527f/ENVIRONMENT_CONTRACT.md)
3. [`RUNTIME_PROFILES.md`](https://github.com/soonshine/ai-collaboration/blob/1719d2a24dfc0cbcac5f5820145e207892b4527f/RUNTIME_PROFILES.md)
4. [`WORKER_PROTOCOL.md`](https://github.com/soonshine/ai-collaboration/blob/1719d2a24dfc0cbcac5f5820145e207892b4527f/WORKER_PROTOCOL.md)
5. [`TASK_CONTRACT.md`](https://github.com/soonshine/ai-collaboration/blob/1719d2a24dfc0cbcac5f5820145e207892b4527f/TASK_CONTRACT.md)
6. [`HANDOFF_PROTOCOL.md`](https://github.com/soonshine/ai-collaboration/blob/1719d2a24dfc0cbcac5f5820145e207892b4527f/HANDOFF_PROTOCOL.md)
7. [`COURIER_PROTOCOL.md`](https://github.com/soonshine/ai-collaboration/blob/1719d2a24dfc0cbcac5f5820145e207892b4527f/COURIER_PROTOCOL.md) when acting as Courier
8. [`PROJECT_ONBOARDING.md`](https://github.com/soonshine/ai-collaboration/blob/1719d2a24dfc0cbcac5f5820145e207892b4527f/PROJECT_ONBOARDING.md)

Verify the checkout before relying on it:

```bash
git -C <ai-collaboration-workspace> rev-parse HEAD
```

Expected result:

```text
1719d2a24dfc0cbcac5f5820145e207892b4527f
```

Keep the protocol checkout outside BoatOps. Do not vendor, fork, or duplicate the shared protocol into this repository.
