# D1 G1 Fictional Demo Deployment Receipt

Status: `D1_COMPLETE / DEMO_DEPLOYED / NOT_PRODUCTION / NOT_RELEASED`

This receipt records the accepted D1 deployment at a non-secret level for repository readers. The authoritative governance decision is `.project/D1_GOVERNANCE_CLOSURE.md`.

## Source

- source: `f9503b598b174b7a6891fcde0d984514a3cd0fcd`
- source change for D1: `NO`
- source CI: GitHub Actions `31294685662` — success
- Tag: none
- GitHub Release: none

## Deployment

- release: `D1_G1_20260809T045741Z`
- public Demo: read-only
- private fictional Operator: loopback-only through SSH tunnel
- data: isolated fictional SQLite only
- real data: none
- production enablement: none

D1 intentionally used two runtime directories from the same exact source rather than merging a D1-specific source change.

## Database and rollback

- D1 SQLite SHA256: `62299484458b8e6f63ca7f457ae713d6d3812e31b654ada8847a6d302596b08f`
- integrity: `ok`
- FK violations: `0`
- rollback script SHA256: `0f785385bd57c8165470f436e71009a11e4971b2687a48d1da36e5e2bacad11a`
- D0.1 source: `3826cb2c29aea4d2b92a90e04c14f8c218fbf45c`
- D0.1 SQLite SHA256: `97d7738c866fa5df6062650da25e644258a4e5c60255c6e1ad83e7ea65632ab4`
- actual D1 -> D0 rollback: PASS
- actual D0 -> D1 restore: PASS

## Workflow

Live workflow reference: `D1-LIVE-43C5C393E126`

Final accepted state:

- booking 6: `CANCELLED`
- allocation 9: `CANCELLED`
- trip 6: `CANCELLED`
- block 3: `RELEASED`
- allocation 10: `RELEASED`
- rate snapshot: none

## Evidence closure

- D1 metadata SHA256: `88f6d500acef3bc24761dcd4467cdc46a4ebce495a98eb7c5d8842b6339b5f4d`
- D1 final receipt SHA256: `cac020f04f719c0c6885e3ea9abdb67eb15d1b913729d8878c50564a33aaa5c1`
- D1 SHA256SUMS SHA256: `1ffc9cbded300c166cefd7cae96d7fdbc22e1c4bcd2e2e346a0d02476200c18b`
- checksum verification: `25/25 PASS`

## Product boundary

This deployment does not establish that the current vessels are owned by Ayany. `boatops.ayany.com` is a deployment hostname. BoatOps remains a reusable organization-scoped product, and vessel ownership/operating relationships are deployment data.

No real customer, order, price, contract, finance, vessel-operation, Google Sheet, ChannelHub, OTA, payment, or production credential data is represented by this receipt.
