import assert from 'node:assert/strict';
import { randomUUID } from 'node:crypto';

const baseUrl = (process.env.BOATOPS_BASE_URL ?? 'http://127.0.0.1:8000').replace(/\/$/, '');
const token = process.env.BOATOPS_TOKEN;
const boatId = Number(process.env.BOATOPS_BOAT_ID ?? 1);
const tripTemplateId = Number(process.env.BOATOPS_TRIP_TEMPLATE_ID ?? 1);
assert.ok(token, 'BOATOPS_TOKEN is required');

async function post(path, body, key) {
  const response = await fetch(`${baseUrl}${path}`, {
    method: 'POST',
    headers: {
      Accept: 'application/json',
      Authorization: `Bearer ${token}`,
      'Content-Type': 'application/json',
      'Idempotency-Key': key,
    },
    body: JSON.stringify(body),
  });
  const text = await response.text();
  let json;
  try { json = JSON.parse(text); } catch { json = { non_json_body: text.slice(0, 300) }; }

  return { status: response.status, body: json };
}

function expect(result, status, code, label) {
  assert.equal(result.status, status, `${label}: ${JSON.stringify(result)}`);
  assert.equal(result.body.code, code, `${label}: ${JSON.stringify(result)}`);
}

const suffix = randomUUID().replaceAll('-', '').slice(0, 12).toUpperCase();
const runId = `FICTIONAL-CORE-SAFETY-${suffix}`;
const offsetDays = Number.parseInt(suffix.slice(0, 4), 16) % 180;
const start = new Date(Date.now() + (900 + offsetDays) * 86400000);
start.setUTCHours(10, 0, 0, 0);
const end = new Date(start.getTime() + 2 * 60 * 60 * 1000);
const expiresAt = new Date(Date.now() + 15 * 60 * 1000);
const now = Date.now();

const setupHold = await post('/api/v1/holds', {
  external_reference: `${runId}-BOOKING`,
  boat_id: boatId,
  trip_template_id: tripTemplateId,
  starts_at: start.toISOString(),
  ends_at: end.toISOString(),
  expires_at: expiresAt.toISOString(),
}, `${runId}-hold-key`);
expect(setupHold, 201, 'HOLD_CREATED', 'setup HOLD');

const confirmed = await post('/api/v1/bookings:confirm', {
  hold_id: setupHold.body.hold_id,
  external_reference: `${runId}-BOOKING`,
  rate_snapshot: {
    source_reference: `${runId}-RATE`,
    currency: 'THB',
    selling_amount_minor: 100000,
    tax_amount_minor: 0,
    commission_amount_minor: 0,
    quoted_at: new Date(now - 60000).toISOString(),
    valid_until: new Date(now + 600000).toISOString(),
  },
}, `${runId}-confirm-key`);
expect(confirmed, 201, 'BOOKING_CONFIRMED', 'setup Booking');

const tripPath = `/api/internal/v1/trips/${confirmed.body.trip_id}`;
const prepared = await post(`${tripPath}:prepare`, {
  crew: [{
    external_reference: `${runId}-CAPTAIN`,
    display_name: 'Fictional Core Safety Captain',
    role: 'CAPTAIN',
    duty: 'CAPTAIN',
  }],
  checklist: [{
    code: 'CORE_SAFETY_READY',
    label: 'Fictional Core Safety readiness',
    required: true,
    completed: true,
  }],
}, `${runId}-prepare-key`);
expect(prepared, 200, 'TRIP_PREPARED', 'prepare');

const departedAt = new Date(now - 120000).toISOString();
const returnedAt = new Date(now - 60000).toISOString();
expect(
  await post(`${tripPath}:depart`, { departed_at: departedAt }, `${runId}-depart-key`),
  200,
  'TRIP_DEPARTED',
  'depart',
);
expect(
  await post(`${tripPath}:return`, { returned_at: returnedAt }, `${runId}-return-key`),
  200,
  'TRIP_RETURNED',
  'return',
);

const completeKey = `${runId}-complete-key`;
const earlyComplete = await post(`${tripPath}:complete`, {}, completeKey);
expect(earlyComplete, 409, 'INVALID_TRANSITION', 'early Complete');

const [competingHold, competingBlock] = await Promise.all([
  post('/api/v1/holds', {
    external_reference: `${runId}-COMPETING-HOLD`,
    boat_id: boatId,
    trip_template_id: tripTemplateId,
    starts_at: start.toISOString(),
    ends_at: end.toISOString(),
    expires_at: expiresAt.toISOString(),
  }, `${runId}-competing-hold-key`),
  post('/api/internal/v1/blocks', {
    external_reference: `${runId}-COMPETING-BLOCK`,
    boat_id: boatId,
    starts_at: start.toISOString(),
    ends_at: end.toISOString(),
    reason_code: 'MANUAL',
    reason: 'FICTIONAL_CORE_SAFETY_CONCURRENCY',
  }, `${runId}-competing-block-key`),
]);
expect(competingHold, 409, 'SLOT_UNAVAILABLE', 'competing HOLD');
expect(competingBlock, 409, 'SLOT_UNAVAILABLE', 'competing BLOCK');

console.log(JSON.stringify({
  status: 'PASS',
  run_id: runId,
  organization_id: confirmed.body.organization_id,
  booking_id: confirmed.body.booking_id,
  trip_id: confirmed.body.trip_id,
  expected_revision: confirmed.body.inventory_revision,
  complete_key: completeKey,
  occupied_start: setupHold.body.occupied_start,
  occupied_end: setupHold.body.occupied_end,
  early_complete: earlyComplete.body.code,
  competing_hold: competingHold.body.code,
  competing_block: competingBlock.body.code,
}));
