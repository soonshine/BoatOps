import assert from 'node:assert/strict';
import { randomUUID } from 'node:crypto';
import { spawn } from 'node:child_process';

const baseUrl = process.env.BOATOPS_BASE_URL ?? 'http://127.0.0.1:18081';
const token = process.env.BOATOPS_TOKEN;
const php = process.env.BOATOPS_PHP ?? '/www/server/php/84/bin/php';
const appDir = process.env.BOATOPS_APP_DIR ?? process.cwd();
const sshHost = process.env.BOATOPS_SSH_HOST;
const boatId = Number(process.env.BOATOPS_BOAT_ID ?? 1);
const templateId = Number(process.env.BOATOPS_TRIP_TEMPLATE_ID ?? 1);
assert.ok(token, 'BOATOPS_TOKEN is required');
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function post(path, body, key) {
  const response = await fetch(`${baseUrl}${path}`, {
    method: 'POST',
    headers: {
      Accept: 'application/json', Authorization: `Bearer ${token}`,
      'Content-Type': 'application/json', 'Idempotency-Key': key,
    },
    body: JSON.stringify(body),
  });
  const text = await response.text();
  let json;
  try { json = JSON.parse(text); } catch { json = { non_json_body: text.slice(0, 300) }; }
  return { status: response.status, body: json };
}

function expireCommand() {
  return new Promise((resolve, reject) => {
    const command = sshHost ? 'ssh' : php;
    const args = sshHost
      ? ['-o', 'BatchMode=yes', sshHost, `cd ${appDir} && ${php} artisan holds:expire`]
      : ['artisan', 'holds:expire'];
    const options = sshHost ? { env: process.env } : { cwd: appDir, env: process.env };
    const child = spawn(command, args, options);
    let stdout = ''; let stderr = '';
    child.stdout.on('data', (data) => { stdout += data; });
    child.stderr.on('data', (data) => { stderr += data; });
    child.on('error', reject);
    child.on('close', (code) => code === 0
      ? resolve(stdout.trim())
      : reject(new Error(`holds:expire exit=${code} ${stderr.slice(0, 300)}`)));
  });
}

function slot(days, hour) {
  const start = new Date(Date.now() + days * 86400000);
  start.setUTCHours(hour, 0, 0, 0);
  return { starts_at: start.toISOString(), ends_at: new Date(start.getTime() + 7200000).toISOString() };
}

function rateSnapshot(now) {
  return {
    source_reference: 'FICTIONAL-RATE-V1', currency: 'THB',
    selling_amount_minor: 125000, tax_amount_minor: 0, commission_amount_minor: 12500,
    fx_rate: '4.50000000', fx_base_currency: 'CNY', fx_quote_currency: 'THB',
    quoted_at: new Date(now - 60000).toISOString(),
    valid_until: new Date(now + 600000).toISOString(),
  };
}

const suffix = randomUUID().replaceAll('-', '').slice(0, 12).toUpperCase();
const runId = `FICTIONAL-EXPIRY-RACE-${suffix}`;
const offset = Number.parseInt(suffix.slice(0, 4), 16) % 120;
const seconds = new Date().getUTCSeconds();
if (seconds > 45) await sleep((61 - seconds) * 1000);

const expiredRef = `${runId}-EXPIRED`;
const expiredSlot = slot(1050 + offset, 10);
const expiresAtMs = Date.now() + 2500;
const expiredHold = await post('/api/v1/holds', {
  external_reference: expiredRef, boat_id: boatId, trip_template_id: templateId,
  ...expiredSlot, expires_at: new Date(expiresAtMs).toISOString(),
}, `${runId}-expired-hold-key`);
assert.equal(expiredHold.status, 201, `expired setup: ${JSON.stringify(expiredHold)}`);
await sleep(Math.max(0, expiresAtMs - Date.now() + 100));
const [expiredConfirm, expiredWorker] = await Promise.all([
  post('/api/v1/bookings:confirm', {
    hold_id: expiredHold.body.hold_id, external_reference: expiredRef,
    rate_snapshot: rateSnapshot(Date.now()),
  }, `${runId}-expired-confirm-key`),
  expireCommand(),
]);
assert.equal(expiredConfirm.status, 409, JSON.stringify(expiredConfirm));
assert.ok(['HOLD_EXPIRED', 'INVALID_TRANSITION'].includes(expiredConfirm.body.code), JSON.stringify(expiredConfirm));
assert.equal(/sqlstate|postgres|allocations_no_active_overlap/i.test(JSON.stringify(expiredConfirm.body)), false);

const validRef = `${runId}-VALID`;
const validSlot = slot(1180 + offset, 10);
const validHold = await post('/api/v1/holds', {
  external_reference: validRef, boat_id: boatId, trip_template_id: templateId,
  ...validSlot, expires_at: new Date(Date.now() + 600000).toISOString(),
}, `${runId}-valid-hold-key`);
assert.equal(validHold.status, 201, `valid setup: ${JSON.stringify(validHold)}`);
const [validConfirm, validWorker] = await Promise.all([
  post('/api/v1/bookings:confirm', {
    hold_id: validHold.body.hold_id, external_reference: validRef,
    rate_snapshot: rateSnapshot(Date.now()),
  }, `${runId}-valid-confirm-key`),
  expireCommand(),
]);
assert.equal(validConfirm.status, 201, JSON.stringify(validConfirm));
assert.equal(validConfirm.body.code, 'BOOKING_CONFIRMED');

console.log(JSON.stringify({
  status: 'PASS', run_id: runId,
  expired: {
    hold_id: expiredHold.body.hold_id, reference: expiredRef,
    confirm_code: expiredConfirm.body.code, worker_result: expiredWorker,
  },
  valid: {
    hold_id: validHold.body.hold_id, booking_id: validConfirm.body.booking_id,
    reference: validRef, confirm_code: validConfirm.body.code, worker_result: validWorker,
  },
}));
