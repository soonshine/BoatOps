# Idempotency rules

- Every write command requires `Idempotency-Key`.
- Scope is `organization + operation + key`.
- `external_reference` is also required and remains stable for the caller's business object.
- The first completed response is stored with a canonical request hash.
- Repeating the same operation, key, and payload returns the stored status and body without creating another business record.
- Reusing a key with another payload or operation returns `IDEMPOTENCY_CONFLICT`.
- Authentication is evaluated before idempotency lookup; one organization cannot replay another organization's result.
- Storage retention is deployment policy and must outlive the longest supported caller retry window.
