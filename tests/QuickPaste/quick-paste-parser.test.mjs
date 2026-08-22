import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';

// Focused regression for the Quick Paste client-side parser shipped in
// resources/views/operator/inquiries/create.blade.php. It executes the REAL
// script (extracted verbatim from the committed Blade view) in a minimal DOM
// shim, so a change to the parser is tested by its actual behavior.

const root = path.resolve(import.meta.dirname, '../..');
const bladePath = path.join(root, 'resources/views/operator/inquiries/create.blade.php');
const blade = fs.readFileSync(bladePath, 'utf8');

const scriptMatch = blade.match(/<script>([\s\S]*?)<\/script>/);
assert.ok(scriptMatch, 'create page must contain the quick paste script');
const script = scriptMatch[1];

const FIELD_NAMES = [
  'service_date', 'reference', 'service_notes', 'selling_currency', 'selling_amount',
  'slot_offering_id', 'boat_id', 'trip_template_id', 'adult_count', 'child_count',
  'party_size', 'contact_name', 'contact_method', 'contact_value', 'hotel_name',
  'meeting_point', 'route_summary', 'service_location', 'pickup_required', 'pickup_time', 'notes',
];

function makeElement(name, options = []) {
  return {
    name,
    value: '',
    options,
    listeners: {},
    addEventListener(event, fn) {
      (this.listeners[event] ??= []).push(fn);
    },
    dispatchEvent() {
      return true;
    },
  };
}

function buildPage() {
  const fields = {};
  for (const name of FIELD_NAMES) fields[name] = makeElement(name);
  const form = {
    elements: {
      namedItem(name) {
        return fields[name] ?? null;
      },
    },
  };
  const input = makeElement('quick-paste-input');
  const parseButton = makeElement('quick-paste-parse');
  const status = makeElement('quick-paste-status');
  const byId = {
    'inquiry-create-form': form,
    'quick-paste-input': input,
    'quick-paste-parse': parseButton,
    'quick-paste-clipboard': makeElement('quick-paste-clipboard'),
    'quick-paste-status': status,
  };
  const context = vm.createContext({
    document: { getElementById(id) { return byId[id] ?? null; } },
    navigator: { clipboard: { readText: async () => '' } },
    Event: class Event {},
    console,
  });
  vm.runInContext(script, context);
  return { fields, input, parseButton, status };
}

function parse(page, text) {
  page.input.value = text;
  for (const fn of page.parseButton.listeners.click ?? []) fn();
  const filled = {};
  for (const [name, element] of Object.entries(page.fields)) {
    if (element.value !== '') filled[name] = element.value;
  }
  return filled;
}

// Real-use sample from the Mission: the five known fields must be filled and
// NO unknown fact may be invented (in particular no fake phone from the date).
{
  const page = buildPage();
  const filled = parse(page, '2026-08-22 4小时收入 14450 THB');
  assert.equal(filled.service_date, '2026-08-22', 'service_date must parse');
  assert.equal(filled.service_notes, '4小时', 'service_notes must parse');
  assert.equal(filled.selling_currency, 'THB', 'selling_currency must parse');
  assert.equal(filled.selling_amount, '14450', 'selling_amount must parse');
  assert.match(filled.reference, /^REAL-\d{8}-\d{6}-[A-Z0-9]{3}$/, 'reference must be a generated REAL-* value');
  assert.ok(!('contact_method' in filled), 'must not invent a contact method from the date digits');
  assert.ok(!('contact_value' in filled), 'must not invent a contact value from the date digits');
  assert.ok(!('adult_count' in filled) && !('child_count' in filled) && !('party_size' in filled), 'unknown passenger facts must stay empty');
  assert.ok(!('boat_id' in filled) && !('trip_template_id' in filled) && !('slot_offering_id' in filled), 'unknown resource facts must stay empty');
  assert.ok(!('hotel_name' in filled) && !('meeting_point' in filled) && !('pickup_time' in filled), 'unknown service facts must stay empty');
  assert.equal(filled.notes, '原始粘贴：\n2026-08-22 4小时收入 14450 THB', 'original text must be preserved in notes');
}

// A genuine Thai mobile number must still be detected and filled.
{
  const page = buildPage();
  const filled = parse(page, '客人 张三 电话 0812345678 4小时收入 14450 THB');
  assert.equal(filled.contact_method, 'PHONE', 'a real phone number must be detected');
  assert.equal(filled.contact_value, '0812345678', 'the real phone number must be filled');
}

console.log('quick-paste parser regression: PASS');
