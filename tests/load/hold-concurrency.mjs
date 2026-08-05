import assert from 'node:assert/strict';
import { randomUUID } from 'node:crypto';

const baseUrl = (process.env.BOATOPS_BASE_URL ?? 'http://127.0.0.1').replace(/\/$/, '');
const host = process.env.BOATOPS_HOST;
const token = process.env.BOATOPS_TOKEN;
const boatId = Number(process.env.BOATOPS_BOAT_ID ?? 1);
const tripTemplateId = Number(process.env.BOATOPS_TRIP_TEMPLATE_ID ?? 1);
const slotOfferingId = process.env.BOATOPS_SLOT_OFFERING_ID
  ? Number(process.env.BOATOPS_SLOT_OFFERING_ID)
  : null;
const slotServiceDate = process.env.BOATOPS_SERVICE_DATE ?? null;
const concurrency = Number(process.env.BOATOPS_CONCURRENCY ?? 100);

if (!token) {
  throw new Error('BOATOPS_TOKEN is required');
}
if ((slotOfferingId === null) !== (slotServiceDate === null)) {
  throw new Error('BOATOPS_SLOT_OFFERING_ID and BOATOPS_SERVICE_DATE must be supplied together');
}
if (slotOfferingId !== null && (!Number.isInteger(slotOfferingId) || slotOfferingId < 1)) {
  throw new Error('BOATOPS_SLOT_OFFERING_ID must be a positive integer');
}
if (slotServiceDate !== null && !/^\d{4}-\d{2}-\d{2}$/.test(slotServiceDate)) {
  throw new Error('BOATOPS_SERVICE_DATE must be YYYY-MM-DD');
}

const runSuffix = randomUUID().slice(0, 8);
const runId = `${Date.now()}-${runSuffix}`;
const runOffsetMinutes = Number.parseInt(runSuffix.slice(0, 4), 16) * 3;
const baseStart = new Date(Date.now() + 370 * 24 * 60 * 60 * 1000 + runOffsetMinutes * 60 * 1000);
baseStart.setUTCSeconds(0, 0);
const expiresAt = new Date(Date.now() + 15 * 60 * 1000).toISOString();

function addUtcDays(date, days) {
  const value = new Date(`${date}T00:00:00Z`);
  value.setUTCDate(value.getUTCDate() + days);

  return value.toISOString().slice(0, 10);
}

async function createHold({ index, start, serviceDate = null, sameKey = false }) {
  const key = sameKey ? `load-same-${runId}` : `load-distinct-${runId}-${index}`;
  const externalReference = sameKey ? `LOAD-FICTIONAL-SAME-${runId}` : `LOAD-FICTIONAL-DISTINCT-${runId}-${index}`;
  const end = new Date(start.getTime() + 2 * 60 * 60 * 1000);
  const intervalSelection = slotOfferingId === null
    ? { starts_at: start.toISOString(), ends_at: end.toISOString() }
    : { slot_offering_id: slotOfferingId, service_date: serviceDate };
  const response = await fetch(`${baseUrl}/api/v1/holds`, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${token}`,
      'Content-Type': 'application/json',
      'Idempotency-Key': key,
      Accept: 'application/json',
      ...(host ? { Host: host } : {}),
    },
    body: JSON.stringify({
      boat_id: boatId,
      trip_template_id: tripTemplateId,
      ...intervalSelection,
      external_reference: externalReference,
      expires_at: expiresAt,
    }),
  });

  let body = {};
  try {
    body = await response.json();
  } catch {
    body = { parse_error: true };
  }

  return { status: response.status, body };
}

const distinctStart = new Date(baseStart);
const distinctServiceDate = slotServiceDate;
const distinct = await Promise.all(
  Array.from({ length: concurrency }, (_, index) => createHold({
    index,
    start: distinctStart,
    serviceDate: distinctServiceDate,
  })),
);
const distinctSuccess = distinct.filter(({ status }) => status === 201);
const slotConflictCodes = new Set(['SLOT_UNAVAILABLE', 'SLOT_COMPATIBILITY_CONFLICT']);
const distinctConflicts = distinct.filter(({ status, body }) => status === 409 && slotConflictCodes.has(body.code));
const distinctUnexpected = distinct.filter(({ status, body }) => status !== 201 && !(status === 409 && slotConflictCodes.has(body.code)));

assert.equal(
  distinctSuccess.length,
  1,
  `expected exactly one distinct-key success; got ${distinctSuccess.length}; sample=${JSON.stringify(distinct.slice(0, 3))}`,
);
assert.equal(distinctConflicts.length, concurrency - 1, `expected ${concurrency - 1} slot conflicts; got ${distinctConflicts.length}`);
assert.equal(distinctUnexpected.length, 0, `unexpected distinct-key responses: ${JSON.stringify(distinctUnexpected.slice(0, 3))}`);

const sameStart = new Date(baseStart.getTime() + 24 * 60 * 60 * 1000);
const sameServiceDate = slotServiceDate === null ? null : addUtcDays(slotServiceDate, 1);
const same = await Promise.all(
  Array.from({ length: concurrency }, (_, index) => createHold({
    index,
    start: sameStart,
    serviceDate: sameServiceDate,
    sameKey: true,
  })),
);
const sameSuccess = same.filter(({ status }) => status === 201);
const holdIds = new Set(sameSuccess.map(({ body }) => body.hold_id));

assert.equal(sameSuccess.length, concurrency, `expected ${concurrency} idempotent successes; got ${sameSuccess.length}`);
assert.equal(holdIds.size, 1, `expected one replayed hold_id; got ${holdIds.size}`);

console.log(JSON.stringify({
  status: 'PASS',
  mode: slotOfferingId === null ? 'legacy_interval' : 'slot_offering',
  slot_offering_id: slotOfferingId,
  service_date: slotServiceDate,
  concurrency,
  distinct_keys: {
    success: distinctSuccess.length,
    slot_conflicts: distinctConflicts.length,
    hold_ids: [...new Set(distinctSuccess.map(({ body }) => body.hold_id))],
  },
  same_key: {
    success: sameSuccess.length,
    unique_hold_ids: holdIds.size,
  },
}, null, 2));
