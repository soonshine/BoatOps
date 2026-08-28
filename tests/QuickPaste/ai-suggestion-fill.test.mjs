import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';

// #51C UI regression: the REAL inline quick-paste + AI suggestion script from
// resources/views/operator/inquiries/create.blade.php must:
// - ask the server-side endpoint (never the provider) for suggestions;
// - fill only EMPTY operator fields and never overwrite non-empty values;
// - present the result as a suggestion and never auto-submit;
// - keep manual entry intact on any AI failure.

const root = path.resolve(import.meta.dirname, '../..');
const bladePath = path.join(root, 'resources/views/operator/inquiries/create.blade.php');
const blade = fs.readFileSync(bladePath, 'utf8');

const scriptMatch = blade.match(/<script>([\s\S]*?)<\/script>/);
assert.ok(scriptMatch, 'create page must contain the quick paste script');
const script = scriptMatch[1];
assert.ok(blade.includes('id="quick-paste-ai"'), 'create page must expose the AI suggestion button');
assert.ok(blade.includes("data-endpoint=\"{{ route('operator.inquiries.ai_suggest') }}\""), 'AI button must carry the server-side endpoint');

const FIELD_NAMES = [
  'service_date', 'reference', 'service_notes', 'selling_currency', 'selling_amount',
  'slot_offering_id', 'boat_id', 'trip_template_id', 'adult_count', 'child_count',
  'party_size', 'contact_name', 'contact_method', 'contact_value', 'hotel_name',
  'meeting_point', 'route_summary', 'service_location', 'pickup_required', 'pickup_time',
  'notes', '_token',
];

function makeElement(name, options = []) {
  return {
    name,
    value: '',
    options,
    textContent: name,
    disabled: false,
    dataset: {},
    listeners: {},
    focus() {},
    addEventListener(event, fn) {
      (this.listeners[event] ??= []).push(fn);
    },
    dispatchEvent() {
      return true;
    },
  };
}

/** @param {object} [setup] optional pre-filled values and fetch payload */
function buildPage({ prefilled = {}, payload = { ok: true, suggestion: {} }, rejectFetch = false } = {}) {
  const fields = {};
  for (const name of FIELD_NAMES) fields[name] = makeElement(name, name === 'boat_id' ? [{ value: 11, textContent: 'Sea Star One' }] : []);
  fields['_token'].value = 'csrf-token';
  for (const [name, value] of Object.entries(prefilled)) fields[name].value = value;
  const form = {
    elements: {
      namedItem(name) {
        return fields[name] ?? null;
      },
    },
  };
  const input = makeElement('quick-paste-input');
  const parseButton = makeElement('quick-paste-parse');
  const clipboardButton = makeElement('quick-paste-clipboard');
  const status = makeElement('quick-paste-status');
  const aiButton = makeElement('quick-paste-ai');
  aiButton.dataset.endpoint = '/operator/inquiries/ai-suggest';
  const byId = {
    'inquiry-create-form': form,
    'quick-paste-input': input,
    'quick-paste-parse': parseButton,
    'quick-paste-clipboard': clipboardButton,
    'quick-paste-status': status,
    'quick-paste-ai': aiButton,
  };
  const fetchCalls = [];
  const context = vm.createContext({
    document: { getElementById(id) { return byId[id] ?? null; } },
    navigator: { clipboard: { readText: async () => '' } },
    Event: class Event { constructor(type) { this.type = type; } },
    console,
    fetch: async (url, options = {}) => {
      fetchCalls.push({ url, options });
      if (rejectFetch) throw new Error('network down');
      return { ok: true, json: async () => payload };
    },
  });
  vm.runInContext(script, context);
  return { fields, input, aiButton, status, fetchCalls };
}

async function clickAi(page, text) {
  page.input.value = text;
  const handler = (page.aiButton.listeners.click ?? [])[0];
  assert.ok(handler, 'AI button must have a click handler');
  await handler();
}

function filledValues(page) {
  const filled = {};
  for (const [name, element] of Object.entries(page.fields)) {
    if (name === '_token') continue; // csrf token, not an operator field
    if (element.value !== '') filled[name] = element.value;
  }
  return filled;
}

// Successful AI suggestion: server endpoint only, fills empty fields, marks as
// a suggestion, preserves raw text locally in notes, never submits.
{
  const page = buildPage({
    payload: {
      ok: true,
      suggestion: {
        service_date: '2026-08-22',
        boat_id: 11,
        boat_resolution: 'RESOLVED',
        boat_name_suggestion: 'Sea Star One',
        route_summary: 'Koh Tan + Koh Madsum',
        contact_name: '王三',
        contact_method: 'WHATSAPP',
        contact_value: '+66 81 234 5678',
        party_size: 4,
        pickup_required: true,
        pickup_time: '08:30',
        hotel_name: 'Sands Resort',
        meeting_point: 'Hotel lobby',
        service_location: 'Koh Samui',
        trip_template_id: null,
        slot_offering_id: null,
        adult_count: null,
        child_count: null,
        child_ages: null,
      },
    },
  });
  await clickAi(page, '2026-08-22 王三 WhatsApp +66 81 234 5678 4人');

  assert.equal(page.fetchCalls.length, 1, 'exactly one server-side parse request');
  assert.equal(page.fetchCalls[0].url, '/operator/inquiries/ai-suggest', 'browser must call the BoatOps endpoint');
  assert.equal(page.fetchCalls[0].options.headers['X-CSRF-TOKEN'], 'csrf-token', 'CSRF token must be sent with the request');
  assert.equal(JSON.parse(page.fetchCalls[0].options.body).raw_text, '2026-08-22 王三 WhatsApp +66 81 234 5678 4人', 'raw text is sent server-side');

  const filled = filledValues(page);
  assert.equal(filled.service_date, '2026-08-22');
  assert.equal(filled.boat_id, '11', 'uniquely resolved boat is suggested');
  assert.equal(filled.contact_name, '王三');
  assert.equal(filled.contact_method, 'WHATSAPP');
  assert.equal(filled.contact_value, '+66 81 234 5678');
  assert.equal(filled.party_size, '4');
  assert.equal(filled.pickup_required, '1', 'boolean pickup suggestion maps to select value');
  assert.equal(filled.pickup_time, '08:30');
  assert.equal(filled.hotel_name, 'Sands Resort');
  assert.equal(filled.route_summary, 'Koh Tan + Koh Madsum');
  assert.ok(!('trip_template_id' in filled), 'null suggestion values must not fill anything');
  assert.ok(!('adult_count' in filled), 'unknown facts must stay empty');
  assert.ok(filled.notes.startsWith('原始粘贴：\n'), 'raw pasted text stays in the local form');
  assert.match(page.status.textContent, /AI 结果仅为建议/, 'the UI must present AI output as a suggestion');
  assert.match(page.status.textContent, /未自动提交/, 'the UI must state there is no auto submit');
  assert.equal(page.aiButton.disabled, false, 'the AI button must be re-enabled after the request');
}

// Non-empty operator-entered fields are never overwritten.
{
  const page = buildPage({
    prefilled: { contact_name: '已在手填', party_size: '3', boat_id: '' },
    payload: {
      ok: true,
      suggestion: {
        contact_name: 'AI 建议姓名',
        party_size: 4,
        boat_id: 11,
        boat_name_suggestion: 'Sea Star One',
        boat_resolution: 'RESOLVED',
        pickup_required: false,
      },
    },
  });
  await clickAi(page, '虚构订单 4人 Sea Star One');

  const filled = filledValues(page);
  assert.equal(filled.contact_name, '已在手填', 'non-empty operator field must never be overwritten');
  assert.equal(filled.party_size, '3', 'non-empty party size must never be overwritten');
  assert.equal(filled.boat_id, '11', 'empty boat select may still receive the resolved suggestion');
  assert.equal(filled.pickup_required, '0', 'boolean false maps to the "no pickup" select value');
}

// Unresolved boat: no boat_id suggestion, only a non-authoritative hint.
{
  const page = buildPage({
    payload: {
      ok: true,
      suggestion: {
        boat_id: null,
        boat_resolution: 'NO_MATCH',
        boat_name_suggestion: 'Plan C',
        contact_name: '李四',
      },
    },
  });
  await clickAi(page, '虚构订单 Plan C 李四');

  const filled = filledValues(page);
  assert.ok(!('boat_id' in filled), 'unresolved boats must never be auto-selected');
  assert.equal(filled.contact_name, '李四', 'other suggestions still apply');
  assert.match(page.status.textContent, /Plan C/, 'the suggested boat name is shown as context');
  assert.match(page.status.textContent, /未自动选择/, 'the UI must state the boat was not auto-selected');
}

// AI disabled / failure: the server returns ok=false with a clear manual-entry
// message; no field changes; the normal form remains usable.
{
  const page = buildPage({
    payload: {
      ok: false,
      code: 'AI_DISABLED',
      message: 'AI 智能识别暂不可用（AI_DISABLED）。未修改任何字段，请直接手工填写表单并点击「创建询价」。',
    },
  });
  await clickAi(page, '虚构订单 王三');

  assert.equal(Object.keys(filledValues(page)).length, 0, 'no field may change on AI failure');
  assert.ok(page.status.textContent.includes('直接手工填写'), 'the fallback message must point to manual entry');
  assert.equal(page.aiButton.disabled, false, 'the AI button must be re-enabled on failure');
}

// Malformed server response: generic manual fallback, form untouched.
{
  const page = buildPage({
    payload: null, // response.json() resolves to null
  });
  await clickAi(page, '虚构订单 王三');

  assert.equal(Object.keys(filledValues(page)).length, 0, 'no field may change on a malformed response');
  assert.ok(page.status.textContent.includes('AI 识别暂不可用'), 'generic manual fallback must be shown');
}

// Network failure: manual fallback, form untouched.
{
  const page = buildPage({ rejectFetch: true });
  await clickAi(page, '虚构订单 王三');

  assert.equal(Object.keys(filledValues(page)).length, 0, 'no field may change when the request fails');
  assert.ok(page.status.textContent.includes('AI 识别暂不可用'), 'network failure must fall back to manual entry');
}

// Empty input: asks the operator to paste first and never calls the server.
{
  const page = buildPage();
  await clickAi(page, '   ');

  assert.equal(page.fetchCalls.length, 0, 'no request for empty input');
  assert.ok(page.status.textContent.includes('先在上方粘贴订单原文'), 'the UI must prompt for the paste first');
}

// #62: pickup_required begins in a genuine UNSET state. The four required
// behaviors, exercised against the REAL blade script:
//   1. unknown input -> pickup_required remains unset (待确认, value '');
//   2. AI says transfer required -> true ('1');
//   3. AI says no transfer -> false ('0');
//   4. operator manually selected a value -> AI MUST NOT overwrite it.
{
  // Case 1: unknown pickup facts (null suggestion) leave the select unset.
  const pageUnknown = buildPage({
    payload: {
      ok: true,
      suggestion: { pickup_required: null, contact_name: 'Test Guest' },
    },
  });
  await clickAi(pageUnknown, '2026-08-30 6人 Koh Tao 海钓 Test Guest');
  const filledUnknown = filledValues(pageUnknown);
  assert.ok(!('pickup_required' in filledUnknown), 'unknown pickup input must leave pickup_required unset');
  assert.equal(filledUnknown.contact_name, 'Test Guest', 'other fields still fill');

  // Case 2: AI says transfer required -> true maps to select value '1'.
  const pageTrue = buildPage({
    payload: { ok: true, suggestion: { pickup_required: true } },
  });
  await clickAi(pageTrue, '需要接送');
  assert.equal(filledValues(pageTrue).pickup_required, '1', 'AI true must map to 需要');

  // Case 3: AI says no transfer -> false maps to select value '0'.
  const pageFalse = buildPage({
    payload: { ok: true, suggestion: { pickup_required: false } },
  });
  await clickAi(pageFalse, 'no transfer 不需要酒店接送');
  assert.equal(filledValues(pageFalse).pickup_required, '0', 'AI false must map to 不需要');

  // Case 4a: operator manually selected 需要 ('1'); AI false must NOT overwrite.
  const pageManualTrue = buildPage({
    prefilled: { pickup_required: '1' },
    payload: { ok: true, suggestion: { pickup_required: false } },
  });
  await clickAi(pageManualTrue, 'no transfer');
  assert.equal(filledValues(pageManualTrue).pickup_required, '1', 'operator-selected 需要 must never be overwritten');

  // Case 4b: operator manually selected 不需要 ('0'); AI true must NOT overwrite.
  const pageManualFalse = buildPage({
    prefilled: { pickup_required: '0' },
    payload: { ok: true, suggestion: { pickup_required: true } },
  });
  await clickAi(pageManualFalse, '需要接送');
  assert.equal(filledValues(pageManualFalse).pickup_required, '0', 'operator-selected 不需要 must never be overwritten');
}

console.log('AI suggestion fill regression: PASS');
