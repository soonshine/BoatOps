/**
 * Manual PostgreSQL concurrency gate.
 * Covers BLOCK versus HOLD and Booking reschedule versus a new HOLD.
 * HOLD expiry versus Booking confirmation is covered by hold-expiry-race.mjs.
 * Requires an explicit target environment, fictional data, and post-run database cleanup evidence.
 */

import assert from 'node:assert/strict';
import { randomUUID } from 'node:crypto';

const baseUrl = process.env.BOATOPS_BASE_URL ?? 'http://127.0.0.1:8000';
const token = process.env.BOATOPS_TOKEN;
const boatId = Number(process.env.BOATOPS_BOAT_ID ?? 1);
const templateId = Number(process.env.BOATOPS_TRIP_TEMPLATE_ID ?? 1);
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

function conflict(result, label) {
  assert.equal(result.status, 409, `${label}: ${JSON.stringify(result)}`);
  assert.equal(result.body.code, 'SLOT_UNAVAILABLE', `${label}: ${JSON.stringify(result)}`);
  const body = JSON.stringify(result.body).toLowerCase();
  for (const term of ['sqlstate', 'allocations_no_active_overlap', 'postgres', 'insert into']) {
    assert.equal(body.includes(term), false, `${label} leaked ${term}`);
  }
}

function slot(days, hour = 10) {
  const start = new Date(Date.now() + days * 86400000);
  start.setUTCHours(hour, 0, 0, 0);
  return {
    starts_at: start.toISOString(),
    ends_at: new Date(start.getTime() + 7200000).toISOString(),
  };
}

const suffix = randomUUID().replaceAll('-', '').slice(0, 12).toUpperCase();
const runId = `FICTIONAL-RACE-${suffix}`;
const offset = Number.parseInt(suffix.slice(0, 4), 16) % 180;
const expires_at = new Date(Date.now() + 900000).toISOString();
const blockSlot = slot(600 + offset);
const holdRef = `${runId}-HOLD`;
const blockRef = `${runId}-BLOCK`;
const [holdRace, blockRace] = await Promise.all([
  post('/api/v1/holds', {
    external_reference: holdRef, boat_id: boatId, trip_template_id: templateId,
    ...blockSlot, expires_at,
  }, `${runId}-hold-key`),
  post('/api/internal/v1/blocks', {
    external_reference: blockRef, boat_id: boatId, ...blockSlot,
    reason_code: 'MANUAL', reason: 'FICTIONAL_CONCURRENCY_GATE',
  }, `${runId}-block-key`),
]);
const bhSuccess = [holdRace, blockRace].filter((x) => x.status === 201);
assert.equal(bhSuccess.length, 1, `BLOCK/HOLD: ${JSON.stringify({ holdRace, blockRace })}`);
const bhType = holdRace.status === 201 ? 'hold' : 'block';
const bhWinner = holdRace.status === 201 ? holdRace : blockRace;
const bhLoser = holdRace.status === 201 ? blockRace : holdRace;
conflict(bhLoser, 'BLOCK/HOLD');
assert.equal(bhWinner.body.code, bhType === 'hold' ? 'HOLD_CREATED' : 'RESOURCE_BLOCKED');

const oldSlot = slot(820 + offset);
const targetSlot = slot(821 + offset);
const bookingRef = `${runId}-BOOKING`;
const setupHold = await post('/api/v1/holds', {
  external_reference: bookingRef,
  boat_id: boatId,
  trip_template_id: templateId,
  ...oldSlot,
  expires_at,
}, `${runId}-setup-hold-key`);
assert.equal(setupHold.status, 201, `setup HOLD: ${JSON.stringify(setupHold)}`);

const now = Date.now();
const confirmed = await post('/api/v1/bookings:confirm', {
  hold_id: setupHold.body.hold_id,
  external_reference: bookingRef,
  rate_snapshot: {
    source_reference: 'FICTIONAL-RATE-V1',
    currency: 'THB',
    selling_amount_minor: 125000,
    tax_amount_minor: 0,
    commission_amount_minor: 12500,
    fx_rate: '4.50000000',
    fx_base_currency: 'CNY',
    fx_quote_currency: 'THB',
    quoted_at: new Date(now - 60000).toISOString(),
    valid_until: new Date(now + 600000).toISOString(),
  },
}, `${runId}-confirm-key`);
assert.equal(confirmed.status, 201, `setup Booking: ${JSON.stringify(confirmed)}`);

const targetHoldRef = `${runId}-TARGET-HOLD`;
const [amendRace, targetHoldRace] = await Promise.all([
  post(`/api/v1/bookings/${confirmed.body.booking_id}:amend`, {
    external_reference: bookingRef,
    boat_id: boatId,
    trip_template_id: templateId,
    ...targetSlot,
  }, `${runId}-amend-key`),
  post('/api/v1/holds', {
    external_reference: targetHoldRef,
    boat_id: boatId,
    trip_template_id: templateId,
    ...targetSlot,
    expires_at,
  }, `${runId}-target-hold-key`),
]);
const amendWon = amendRace.status === 200;
const targetHoldWon = targetHoldRace.status === 201;
assert.equal(Number(amendWon) + Number(targetHoldWon), 1,
  `amend/HOLD: ${JSON.stringify({ amendRace, targetHoldRace })}`);
conflict(amendWon ? targetHoldRace : amendRace, 'amend/HOLD');
assert.equal(amendWon ? amendRace.body.code : targetHoldRace.body.code,
  amendWon ? 'BOOKING_AMENDED' : 'HOLD_CREATED');

const manifest = {
  status: 'PASS',
  run_id: runId,
  block_hold: {
    starts_at: blockSlot.starts_at,
    ends_at: blockSlot.ends_at,
    hold_reference: holdRef,
    block_reference: blockRef,
    winner_type: bhType,
    winner_id: bhType === 'hold' ? bhWinner.body.hold_id : bhWinner.body.block_id,
    loser_code: bhLoser.body.code,
  },
  amend_hold: {
    starts_at: targetSlot.starts_at,
    ends_at: targetSlot.ends_at,
    booking_id: confirmed.body.booking_id,
    booking_reference: bookingRef,
    winner_type: amendWon ? 'amend' : 'hold',
    hold_id: targetHoldWon ? targetHoldRace.body.hold_id : null,
    hold_reference: targetHoldRef,
    loser_code: amendWon ? targetHoldRace.body.code : amendRace.body.code,
  },
};
console.log(JSON.stringify(manifest));
