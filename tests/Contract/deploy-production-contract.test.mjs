import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(import.meta.dirname, '../..');
const deployScript = fs.readFileSync(path.join(root, 'deploy/scripts/deploy-production.sh'), 'utf8');
const runbook = fs.readFileSync(path.join(root, 'deploy/PRODUCTION.md'), 'utf8');
const scheduler = fs.readFileSync(path.join(root, 'deploy/cron/boatops-scheduler'), 'utf8');

assert.match(deployScript, /APP_ENV\)\" == \"production/, 'production environment must be enforced');
assert.match(deployScript, /APP_DEBUG\)\" == \"false/, 'debug must be disabled');
assert.ok(
  deployScript.includes('APP_URL') && deployScript.includes('https://boatops.ayany.com'),
  'production URL must be enforced',
);
assert.match(deployScript, /DB_CONNECTION\)\" == \"pgsql/, 'PostgreSQL must be enforced');
assert.match(deployScript, /env_value APP_KEY/, 'APP_KEY must be checked');
assert.match(deployScript, /git SHA must be a full 40-character commit SHA/, 'deploy must require an exact full SHA');
assert.match(deployScript, /ACTUAL_SHA=\"\$\(git rev-parse HEAD\)/, 'checked-out SHA must be verified');
assert.match(deployScript, /--backup-confirmed/, 'deployment must require an operator backup acknowledgement');
assert.match(deployScript, /artisan migrate --force/, 'production migrations must remain explicit');
assert.match(deployScript, /SCHEDULER_CRON_FILE/, 'scheduler path must be configurable and checked');
assert.match(deployScript, /schedule:run/, 'scheduler command must be checked');
assert.ok(
  deployScript.includes("grep -Eq '^[[:space:]]*\\*[[:space:]]+\\*[[:space:]]+\\*[[:space:]]+\\*[[:space:]]+\\*[[:space:]]+'"),
  'scheduler must be checked for a five-field every-minute entry',
);
assert.match(deployScript, /systemctl restart \"\$QUEUE_SERVICE\"/, 'queue worker must be restarted');
assert.match(deployScript, /SMOKE_BASE.*\/up/, 'health smoke check must remain');
assert.match(deployScript, /operator\/today/, 'Operator redirect smoke checks must remain');
assert.match(deployScript, /rollback_code/, 'rollback path must remain');
assert.match(deployScript, /CURRENT_SHA=\"\$\(git -C \"\$CURRENT\" rev-parse HEAD\)/, 'current release SHA must be verified');

assert.doesNotMatch(deployScript, /NPM_BIN|npm ci|npm run build/, 'npm must not be a production prerequisite');
assert.match(deployScript, /BOATOPS_DEMO_SITE_ENABLED is unset/, 'missing Demo config must be a notice');
assert.match(deployScript, /BOATOPS_DEMO_SITE_ENABLED must be false when configured/, 'explicit Demo enablement must fail closed');

assert.match(scheduler, /\* \* \* \* \* .*artisan schedule:run/, 'committed scheduler must run every minute');
assert.match(runbook, /Demo variables are optional/, 'runbook must document safe Demo defaults');
assert.match(runbook, /SESSION_DRIVER.*CACHE_STORE.*not pinned/s, 'session/cache backends must not be hard blockers');
assert.match(runbook, /Node\.js\/npm or a Vite build/, 'runbook must document the non-blocking frontend build path');
assert.match(runbook, /scheduler.*required production dependency/i, 'runbook must retain the scheduler blocker');

console.log('PASS production deployment contract');
