import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import Ajv2020 from 'ajv/dist/2020.js';
import addFormats from 'ajv-formats';

const root = path.resolve(import.meta.dirname, '../..');
const contractDir = path.join(root, 'contracts/inventory-provider/v1');
const ajv = new Ajv2020({ allErrors: true, strict: true });
addFormats(ajv);

const fixtures = [
  ['booking.confirmed.v1.schema.json', 'booking-confirmed.json'],
  ['hold.created.v1.schema.json', 'hold-created.json'],
  ['resource.blocked.v1.schema.json', 'resource-blocked.json'],
  ['resource.unblocked.v1.schema.json', 'resource-unblocked.json'],
  ['trip.completed.v1.schema.json', 'trip-completed.json'],
];

for (const [schemaName, exampleName] of fixtures) {
  const schema = JSON.parse(fs.readFileSync(path.join(contractDir, 'events', schemaName), 'utf8'));
  const example = JSON.parse(fs.readFileSync(path.join(contractDir, 'examples', exampleName), 'utf8'));
  const validate = ajv.compile(schema);
  const valid = validate(example);

  assert.equal(
    valid,
    true,
    `${exampleName} must satisfy ${schemaName}\n${ajv.errorsText(validate.errors, { separator: '\n' })}`,
  );
}

console.log(`PASS event examples: ${fixtures.length} fixtures validated against JSON Schema draft 2020-12`);
