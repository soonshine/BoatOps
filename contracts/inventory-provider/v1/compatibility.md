# v1 compatibility policy

- `1.0.0-alpha.1` is a local alpha contract and is not a public release.
- Within stable v1, new optional fields may be added without breaking consumers.
- Consumers must ignore unknown fields but must not treat unknown states or capability results as success.
- Removing a field, changing its meaning, changing idempotency behavior, or narrowing a published enum requires a new major contract version.
- A contract release must include checksums, a changelog, and an explicit compatibility conclusion.
- No license is declared here while the repository license decision remains `PROPOSED_NOT_FROZEN`.
