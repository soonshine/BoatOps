import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(import.meta.dirname, '../..');
const contractDir = path.join(root, 'contracts/inventory-provider/v1');
const openapiPath = path.join(contractDir, 'openapi.yaml');
const errorsPath = path.join(contractDir, 'errors.yaml');

assert.ok(fs.existsSync(openapiPath), 'openapi.yaml must exist');
assert.ok(fs.existsSync(errorsPath), 'errors.yaml must exist');

const openapi = fs.readFileSync(openapiPath, 'utf8');
const errors = fs.readFileSync(errorsPath, 'utf8');

assert.match(openapi, /version: 1\.0\.0-alpha\.3/, 'inventory contract version must be alpha.3');
assert.match(openapi, /required: \[hold_id, external_reference, rate_snapshot\]/, 'booking confirmation must freeze a rate snapshot');
assert.match(openapi, /selling_amount_minor/, 'rate snapshot must use integer minor units');

const requiredPaths = [
  '/api/v1/availability:check',
  '/api/v1/holds',
  '/api/v1/holds/{id}:release',
  '/api/v1/bookings:confirm',
  '/api/v1/bookings/{id}:amend',
  '/api/v1/bookings/{id}:cancel',
  '/api/v1/inventory/revision',
];
for (const apiPath of requiredPaths) {
  assert.match(openapi, new RegExp(apiPath.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')), `${apiPath} must be documented`);
}

for (const operationId of ['createHold', 'releaseHold', 'confirmBooking', 'amendBooking', 'cancelBooking']) {
  const start = openapi.indexOf(`operationId: ${operationId}`);
  assert.notEqual(start, -1, `${operationId} must exist`);
  const operationBlock = openapi.slice(start, start + 1600);
  assert.match(operationBlock, /Idempotency-Key/, `${operationId} must require Idempotency-Key`);
  assert.match(operationBlock, /inventory_revision/, `${operationId} success response must expose inventory_revision`);
}

for (const code of [
  'SLOT_UNAVAILABLE',
  'HOLD_EXPIRED',
  'DUPLICATE_EXTERNAL_REFERENCE',
  'INVALID_TRANSITION',
  'RATE_CHANGED',
  'AUTHORIZATION_FAILED',
  'VALIDATION_FAILED',
  'TEMPORARY_UNAVAILABLE',
  'IDEMPOTENCY_CONFLICT',
]) {
  assert.match(errors, new RegExp(`\\b${code}\\b`), `${code} must be stable and documented`);
}

const eventNames = [
  'inventory.revision.changed.v1',
  'hold.created.v1',
  'hold.expired.v1',
  'booking.confirmed.v1',
  'booking.amended.v1',
  'booking.cancelled.v1',
  'resource.blocked.v1',
  'resource.unblocked.v1',
  'trip.completed.v1',
];
for (const eventName of eventNames) {
  const schemaPath = path.join(contractDir, 'events', `${eventName}.schema.json`);
  assert.ok(fs.existsSync(schemaPath), `${eventName} schema must exist`);
  const schema = JSON.parse(fs.readFileSync(schemaPath, 'utf8'));
  assert.equal(schema.$schema, 'https://json-schema.org/draft/2020-12/schema');
  assert.equal(schema.properties.event_type.const, eventName);
  assert.ok(schema.required.includes('organization_id'));
  const serialized = JSON.stringify(schema).toLowerCase();
  for (const forbidden of ['customer_name', 'phone', 'email', 'cost', 'profit']) {
    assert.ok(!serialized.includes(forbidden), `${eventName} must not expose ${forbidden}`);
  }
}

console.log(`PASS contract structure: ${requiredPaths.length} endpoints, ${eventNames.length} event schemas`);
