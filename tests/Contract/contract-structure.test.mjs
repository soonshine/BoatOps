import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(import.meta.dirname, '../..');
const contractDir = path.join(root, 'contracts/inventory-provider/v1');
const openapiPath = path.join(contractDir, 'openapi.yaml');
const errorsPath = path.join(contractDir, 'errors.yaml');
const operationsOpenapiPath = path.join(root, 'contracts/operations/v1/openapi.yaml');

assert.ok(fs.existsSync(openapiPath), 'openapi.yaml must exist');
assert.ok(fs.existsSync(errorsPath), 'errors.yaml must exist');
assert.ok(fs.existsSync(operationsOpenapiPath), 'operations openapi.yaml must exist');

const openapi = fs.readFileSync(openapiPath, 'utf8');
const errors = fs.readFileSync(errorsPath, 'utf8');
const operationsOpenapi = fs.readFileSync(operationsOpenapiPath, 'utf8');

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
  'SLOT_COMPATIBILITY_CONFLICT',
  'SLOT_CROSSES_MIDNIGHT',
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

const availabilityExamplePath = path.join(contractDir, 'examples', 'availability-check.json');
const availabilityExample = JSON.parse(fs.readFileSync(availabilityExamplePath, 'utf8'));
assert.deepEqual(
  Object.keys(availabilityExample).sort(),
  ['boat_id', 'ends_at', 'starts_at', 'trip_template_id'],
  'availability example must contain exactly the documented request fields',
);
assert.ok(Number.isInteger(availabilityExample.boat_id) && availabilityExample.boat_id > 0, 'availability boat_id must be a positive integer');
assert.ok(Number.isInteger(availabilityExample.trip_template_id) && availabilityExample.trip_template_id > 0, 'availability trip_template_id must be a positive integer');
assert.ok(!Number.isNaN(Date.parse(availabilityExample.starts_at)), 'availability starts_at must be a date-time');
assert.ok(!Number.isNaN(Date.parse(availabilityExample.ends_at)), 'availability ends_at must be a date-time');
assert.ok(Date.parse(availabilityExample.ends_at) > Date.parse(availabilityExample.starts_at), 'availability ends_at must follow starts_at');

for (const slotField of ['slot_offering_id', 'custom_slot_instance_id', 'service_date', 'service_start', 'service_end']) {
  assert.match(openapi, new RegExp(`\\b${slotField}\\b`), `${slotField} must be documented`);
}
const slotAvailabilityExamplePath = path.join(contractDir, 'examples', 'availability-slot-check.json');
const slotAvailabilityExample = JSON.parse(fs.readFileSync(slotAvailabilityExamplePath, 'utf8'));
assert.deepEqual(
  Object.keys(slotAvailabilityExample).sort(),
  ['boat_id', 'service_date', 'slot_offering_id', 'trip_template_id'],
  'identified-slot availability example must contain exactly the documented request fields',
);
assert.match(slotAvailabilityExample.service_date, /^\d{4}-\d{2}-\d{2}$/, 'slot service_date must be organization-local YYYY-MM-DD');
assert.ok(Number.isInteger(slotAvailabilityExample.slot_offering_id) && slotAvailabilityExample.slot_offering_id > 0, 'slot_offering_id must be positive');

assert.match(operationsOpenapi, /version: 1\.0\.0-alpha\.5/, 'operations contract version must be alpha.5');
assert.match(operationsOpenapi, /operations\.finance\.write/, 'finance write scope must be documented');
assert.match(operationsOpenapi, /operations\.finance\.read/, 'finance read scope must be documented');
assert.match(operationsOpenapi, /operations\.schedule\.write/, 'schedule write scope must be documented');
assert.match(operationsOpenapi, /operations\.schedule\.read/, 'schedule read scope must be documented');

const requiredOperationsPaths = [
  '/api/internal/v1/blocks',
  '/api/internal/v1/blocks/{id}:release',
  '/api/internal/v1/trips/{id}:prepare',
  '/api/internal/v1/trips/{id}:depart',
  '/api/internal/v1/trips/{id}:return',
  '/api/internal/v1/trips/{id}:complete',
  '/api/internal/v1/schedule/slot-offerings',
  '/api/internal/v1/schedule/custom-slot-instances',
  '/api/internal/v1/schedule/compatibility-rules',
  '/api/internal/v1/schedule/slot-offerings/{id}:activate',
  '/api/internal/v1/schedule/slot-offerings/{id}:retire',
  '/api/internal/v1/schedule/calendar',
  '/api/internal/v1/finance/accounts',
  '/api/internal/v1/finance/accounts/{id}/activity',
  '/api/internal/v1/finance/accounts/{id}/daily-summary',
  '/api/internal/v1/finance/expense-categories',
  '/api/internal/v1/stock/items',
  '/api/internal/v1/fuel-logs',
  '/api/internal/v1/expenses',
  '/api/internal/v1/stock/movements',
  '/api/internal/v1/fuel-logs/{id}:reverse',
  '/api/internal/v1/expenses/{id}:reverse',
  '/api/internal/v1/stock/movements/{id}:reverse',
  '/api/internal/v1/trips/{id}/cost-summary',
  '/api/internal/v1/boats/{id}/daily-cost-summary',
  '/api/internal/v1/stock/balances',
];
assert.equal(requiredOperationsPaths.length, 26, 'operations contract must expose exactly 26 verified path templates');
for (const apiPath of requiredOperationsPaths) {
  assert.match(operationsOpenapi, new RegExp(apiPath.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')), `${apiPath} must be documented`);
}

const requiredScheduleOperationIds = [
  'listSlotOfferings',
  'createSlotOffering',
  'createCustomSlotInstance',
  'setSlotCompatibilityRule',
  'activateSlotOffering',
  'retireSlotOffering',
  'getScheduleCalendar',
];
assert.equal(requiredScheduleOperationIds.length, 7, 'schedule contract must expose exactly 7 operations');
for (const operationId of requiredScheduleOperationIds) {
  assert.match(operationsOpenapi, new RegExp(`operationId: ${operationId}\\b`), `${operationId} must exist`);
}
for (const operationId of [
  'createSlotOffering',
  'createCustomSlotInstance',
  'setSlotCompatibilityRule',
  'activateSlotOffering',
  'retireSlotOffering',
]) {
  const start = operationsOpenapi.indexOf(`operationId: ${operationId}`);
  assert.match(operationsOpenapi.slice(start, start + 900), /audit row/i, `${operationId} must document audit logging`);
}
for (const status of ['AVAILABLE', 'HELD', 'CONFIRMED', 'BLOCKED', 'UNAVAILABLE']) {
  assert.match(operationsOpenapi, new RegExp(`\\b${status}\\b`), `calendar status ${status} must be documented`);
}
for (const code of ['SLOT_UNAVAILABLE', 'SLOT_COMPATIBILITY_CONFLICT']) {
  assert.match(operationsOpenapi, new RegExp(`\\b${code}\\b`), `calendar conflict ${code} must be documented`);
}
assert.match(operationsOpenapi, /maxItems: 31/, 'calendar response must cap local dates at 31');
assert.match(operationsOpenapi, /DEMO DEFAULT \/ UNVERIFIED OPERATING TIME/, 'unverified demo time marker must be prominent');
const scheduleSchemaStart = operationsOpenapi.indexOf('    CreateSlotOfferingRequest:');
const scheduleSchemaEnd = operationsOpenapi.indexOf('    CreateBlockRequest:', scheduleSchemaStart);
const scheduleSchemas = operationsOpenapi.slice(scheduleSchemaStart, scheduleSchemaEnd).toLowerCase();
for (const forbiddenField of ['customer_name:', 'customer_phone:', 'phone_number:', 'hotel_name:', 'room_number:', 'selling_amount_minor:', 'price:']) {
  assert.ok(!scheduleSchemas.includes(forbiddenField), `schedule schemas must not expose ${forbiddenField}`);
}

for (const operationId of [
  'createCashAccount',
  'createExpenseCategory',
  'createStockItem',
  'recordFuelLog',
  'recordExpense',
  'recordStockMovement',
  'reverseFuelLog',
  'reverseExpense',
  'reverseStockMovement',
]) {
  const start = operationsOpenapi.indexOf(`operationId: ${operationId}`);
  assert.notEqual(start, -1, `${operationId} must exist`);
  assert.match(operationsOpenapi.slice(start, start + 1200), /IdempotencyKey/, `${operationId} must require Idempotency-Key`);
}

console.log(`PASS contract structure: ${requiredPaths.length} inventory endpoints, ${requiredOperationsPaths.length} operations path templates / 27 operations, ${eventNames.length} event schemas`);
