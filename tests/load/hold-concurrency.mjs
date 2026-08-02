import assert from 'node:assert/strict';
import { randomUUID } from 'node:crypto';

const baseUrl = (process.env.BOATOPS_BASE_URL ?? 'http://127.0.0.1').replace(/\/$/, '');
const host = process.env.BOATOPS_HOST;
const token = process.env.BOATOPS_TOKEN;
const boatId = Number(process.env.BOATOPS_BOAT_ID ?? 1);
const tripTemplateId = Number(process.env.BOATOPS_TRIP_TEMPLATE_ID ?? 1);
const concurrency = Number(process.env.BOATOPS_CONCURRENCY ?? 100);

if (!token) {
  throw new Error('BOATOPS_TOKEN is required');
}

const runSuffix = randomUUID().slice(0, 8);
const runId = `${Date.now()}-${runSuffix}`;
const runOffsetMinutes = Number.parseInt(runSuffix.slice(0, 4), 16) * 3;
const baseStart = new Date(Date.now() + 370 * 24 * 60 * 60 * 1000 + runOffsetMinutes * 60 * 1000);
baseStart.setUTCSeconds(0, 0);
const expiresAt = new Date(Date.now() + 15 * 60 * 1000).toISOString();

async function createHold({ index, start, sameKey = false }) {
  const key = sameKey ? `load-same-${runId}` : `load-distinct-${runId}-${index}`;
  const externalReference = sameKey ? `LOAD-FICTIONAL-SAME-${runId}` : `LOAD-FICTIONAL-DISTINCT-${runId}-${index}`;
  const end = new Date(start.getTime() + 2 * 60 * 60 * 1000);
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
      starts_at: start.toISOString(),
      ends_at: end.toISOString(),
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
const distinct = await Promise.all(
  Array.from({ length: concurrency }, (_, index) => createHold({ index, start: distinctStart })),
);
const distinctSuccess = distinct.filter(({ status }) => status === 201);
const distinctConflicts = distinct.filter(({ status, body }) => status === 409 && body.code === 'SLOT_UNAVAILABLE');
const distinctUnexpected = distinct.filter(({ status, body }) => status !== 201 && !(status === 409 && body.code === 'SLOT_UNAVAILABLE'));

assert.equal(
  distinctSuccess.length,
  1,
  `expected exactly one distinct-key success; got ${distinctSuccess.length}; sample=${JSON.stringify(distinct.slice(0, 3))}`,
);
assert.equal(distinctConflicts.length, concurrency - 1, `expected ${concurrency - 1} slot conflicts; got ${distinctConflicts.length}`);
assert.equal(distinctUnexpected.length, 0, `unexpected distinct-key responses: ${JSON.stringify(distinctUnexpected.slice(0, 3))}`);

const sameStart = new Date(baseStart.getTime() + 24 * 60 * 60 * 1000);
const same = await Promise.all(
  Array.from({ length: concurrency }, (_, index) => createHold({ index, start: sameStart, sameKey: true })),
);
const sameSuccess = same.filter(({ status }) => status === 201);
const holdIds = new Set(sameSuccess.map(({ body }) => body.hold_id));

assert.equal(sameSuccess.length, concurrency, `expected ${concurrency} idempotent successes; got ${sameSuccess.length}`);
assert.equal(holdIds.size, 1, `expected one replayed hold_id; got ${holdIds.size}`);

console.log(JSON.stringify({
  status: 'PASS',
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
