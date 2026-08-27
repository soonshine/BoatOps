import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

const root = path.resolve(import.meta.dirname, '../..');
const deployScript = fs.readFileSync(path.join(root, 'deploy/scripts/deploy-production.sh'), 'utf8');
const runbook = fs.readFileSync(path.join(root, 'deploy/PRODUCTION.md'), 'utf8');
const scheduler = fs.readFileSync(path.join(root, 'deploy/cron/boatops-scheduler'), 'utf8');

function extractShellFunction(name) {
  const match = deployScript.match(new RegExp(`^${name}\\(\\) \\{\\r?\\n[\\s\\S]*?^\\}`, 'm'));
  assert.ok(match, `deploy script must define ${name}()`);

  return match[0];
}

function bashPath(filePath) {
  return filePath.replaceAll('\\', '/');
}

const frontendFunctions = [
  extractShellFunction('fail'),
  extractShellFunction('blade_requires_frontend_build'),
  extractShellFunction('release_requires_frontend_build'),
  extractShellFunction('build_frontend_if_required'),
].join('\n\n');

function classifyFrontend(releaseRoot) {
  const result = spawnSync(
    'bash',
    [
      '-c',
      `${frontendFunctions}\nif release_requires_frontend_build "$1"; then printf REQUIRED; else printf SKIP; fi`,
      'boatops-deploy-contract',
      bashPath(releaseRoot),
    ],
    { encoding: 'utf8' },
  );
  assert.equal(result.status, 0, result.stderr || 'frontend release-content check failed');

  return result.stdout;
}

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
assert.doesNotMatch(deployScript, /QUEUE_SERVICE|systemctl/, 'unused queue worker must not gate deployment');
assert.match(deployScript, /SMOKE_BASE.*\/up/, 'health smoke check must remain');
assert.match(deployScript, /operator\/today/, 'Operator redirect smoke checks must remain');
assert.match(deployScript, /rollback_code/, 'rollback path must remain');
assert.match(deployScript, /CURRENT_SHA=\"\$\(git -C \"\$CURRENT\" rev-parse HEAD\)/, 'current release SHA must be verified');

const redirectAssertionLines = deployScript
  .split(/\r?\n/)
  .filter((line) => /^echo \"\$(ROOT|TODAY)_HEADERS\" .*grep -Eqi /.test(line));
assert.equal(redirectAssertionLines.length, 4, 'both redirect checks must retain status and Location assertions');
assert.ok(
  redirectAssertionLines.every((line) => line.includes("tr -d '\\r'")),
  'all redirect header assertions must normalize CRLF before matching',
);
assert.ok(
  redirectAssertionLines.every((line) => !line.includes('\\r?')),
  'redirect assertions must not use the non-portable CRLF ERE marker',
);

const redirectPipelines = redirectAssertionLines.map((line) => line.split(' || ', 1)[0].trim());
const redirectSmoke = [
  'set -Eeuo pipefail',
  'grep() {',
  '  for argument in "$@"; do',
  "    if [[ \"$argument\" == *'\\r'* ]]; then",
  "      printf '%s\\n' 'grep: stray backslash before r' >&2",
  '      return 2',
  '    fi',
  '  done',
  '  command grep "$@"',
  '}',
  "ROOT_HEADERS=$'HTTP/1.1 302 Found\\r\\nLocation: /operator/today\\r\\n'",
  "TODAY_HEADERS=$'HTTP/1.1 302 Found\\r\\nLocation: https://boatops.ayany.com/operator/login\\r\\n'",
  ...redirectPipelines,
].join('\n');
const redirectSmokeResult = spawnSync('bash', ['-c', redirectSmoke], { encoding: 'utf8' });
assert.equal(
  redirectSmokeResult.status,
  0,
  redirectSmokeResult.stderr || 'CRLF redirect headers must pass the portable smoke assertions',
);

assert.match(deployScript, /release_requires_frontend_build/, 'release content must control frontend builds');
assert.match(deployScript, /command -v \"\$NPM_BIN\"/, 'npm capability must fail closed when frontend assets are required');
assert.match(deployScript, /\"\$NPM_BIN\" ci --ignore-scripts/, 'required frontend assets must use the lockfile install');
assert.match(deployScript, /\"\$NPM_BIN\" run build/, 'required frontend assets must be built');
assert.match(deployScript, /frontend build did not produce a Vite\/Mix manifest/, 'frontend output must be verified');
assert.match(deployScript, /BOATOPS_DEMO_SITE_ENABLED is unset/, 'missing Demo config must be a notice');
assert.match(deployScript, /BOATOPS_DEMO_SITE_ENABLED must be false when configured/, 'explicit Demo enablement must fail closed');

assert.match(scheduler, /\* \* \* \* \* .*artisan schedule:run/, 'committed scheduler must run every minute');
assert.match(runbook, /Demo variables are optional/, 'runbook must document safe Demo defaults');
assert.match(runbook, /SESSION_DRIVER.*CACHE_STORE.*not pinned/s, 'session/cache backends must not be hard blockers');
assert.match(runbook, /release-content check.*public\/build\/manifest\.json/s, 'runbook must document conditional frontend detection');
assert.match(runbook, /release with that content skips npm/, 'runbook must document content-based npm skip');
assert.doesNotMatch(runbook, /approved target `[0-9a-f]{40}`/, 'runbook must not pin a historical SHA as a permanent approved target');
assert.match(runbook, /re-verifies the candidate exact SHA, the checked-out release content, and the resulting runtime capability/, 'runbook must document capability verification from the candidate release');
assert.match(runbook, /queue worker is not a current deployment or live gate/i, 'runbook must document the queue decision');
assert.match(runbook, /scheduler.*required production dependency/i, 'runbook must retain the scheduler blocker');

const fixturesRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'boatops-deploy-contract-'));
try {
  const bladeOnlyRelease = path.join(fixturesRoot, 'blade-only');
  fs.mkdirSync(path.join(bladeOnlyRelease, 'resources'), { recursive: true });
  fs.cpSync(path.join(root, 'resources/views'), path.join(bladeOnlyRelease, 'resources/views'), { recursive: true });
  assert.equal(classifyFrontend(bladeOnlyRelease), 'SKIP', 'approved Blade-only view content must skip npm');
  const bladeWithoutNpm = spawnSync(
    'bash',
    [
      '-c',
      `${frontendFunctions}\nNPM_BIN=boatops-contract-missing-npm\nbuild_frontend_if_required "$1"`,
      'boatops-deploy-contract',
      bashPath(bladeOnlyRelease),
    ],
    { encoding: 'utf8' },
  );
  assert.equal(bladeWithoutNpm.status, 0, 'approved Blade-only content must proceed without npm');
  assert.match(bladeWithoutNpm.stdout, /skipping npm/);

  const viteRelease = path.join(fixturesRoot, 'vite');
  fs.mkdirSync(path.join(viteRelease, 'resources/views'), { recursive: true });
  fs.writeFileSync(path.join(viteRelease, 'resources/views/app.blade.php'), "@vite(['resources/js/app.js'])\n");
  assert.equal(classifyFrontend(viteRelease), 'REQUIRED', 'unguarded @vite must require a frontend build');

  const manifestRelease = path.join(fixturesRoot, 'manifest');
  fs.mkdirSync(path.join(manifestRelease, 'public/build'), { recursive: true });
  fs.writeFileSync(path.join(manifestRelease, 'public/build/manifest.json'), '{}\n');
  assert.equal(classifyFrontend(manifestRelease), 'REQUIRED', 'a committed Vite manifest must require a frontend build');

  const mixRelease = path.join(fixturesRoot, 'mix');
  fs.mkdirSync(path.join(mixRelease, 'resources/views'), { recursive: true });
  fs.writeFileSync(path.join(mixRelease, 'resources/views/app.blade.php'), "{{ mix('css/app.css') }}\n");
  assert.equal(classifyFrontend(mixRelease), 'REQUIRED', 'a Blade mix() reference must require a frontend build');

  const missingNpm = spawnSync(
    'bash',
    [
      '-c',
      `${frontendFunctions}\nNPM_BIN=boatops-contract-missing-npm\nbuild_frontend_if_required "$1"`,
      'boatops-deploy-contract',
      bashPath(viteRelease),
    ],
    { encoding: 'utf8' },
  );
  assert.notEqual(missingNpm.status, 0, 'Vite-referencing releases must fail when npm is unavailable');
  assert.match(missingNpm.stderr, /release requires Vite\/Mix assets but npm is unavailable/);

  fs.writeFileSync(path.join(viteRelease, 'package.json'), '{}\n');
  fs.writeFileSync(path.join(viteRelease, 'package-lock.json'), '{}\n');
  fs.writeFileSync(path.join(viteRelease, 'ci'), '#!/usr/bin/env bash\nexit 0\n');
  fs.writeFileSync(
    path.join(viteRelease, 'run'),
    '#!/usr/bin/env bash\n[[ "${1:-}" == build ]] || exit 2\nmkdir -p public/build\nprintf "{}\\n" > public/build/manifest.json\n',
  );
  const availableNpm = spawnSync(
    'bash',
    [
      '-c',
      `${frontendFunctions}\nNPM_BIN=bash\nbuild_frontend_if_required "$1"`,
      'boatops-deploy-contract',
      bashPath(viteRelease),
    ],
    { encoding: 'utf8' },
  );
  assert.equal(availableNpm.status, 0, availableNpm.stderr || 'conditional frontend build failed');
  assert.ok(
    fs.existsSync(path.join(viteRelease, 'public/build/manifest.json')),
    'conditional frontend build must verify its generated manifest',
  );
} finally {
  fs.rmSync(fixturesRoot, { recursive: true, force: true });
}


// --- Issue #49: non-root repository execution boundary + deployment mutex ---
assert.match(deployScript, /flock -n 9/, 'deployment must acquire a single-instance flock mutex');
assert.match(deployScript, /LOCK_FILE/, 'deployment mutex lock file must be configurable');
assert.match(deployScript, /another deployment holds the single-instance mutex/, 'mutex contention must fail with a clear message');
assert.match(deployScript, /WEB_USER must be a non-root deploy user/, 'deploy user must be enforced non-root');
assert.match(deployScript, /must not be uid 0/, 'deploy user must not be uid 0');
assert.match(deployScript, /runuser|RUNUSER_BIN/, 'minimal runuser privilege-drop primitive must be supported');
assert.match(deployScript, /SU_BIN/, 'su fallback privilege-drop primitive must be supported');
assert.match(deployScript, /run_repository_command/, 'repository commands must be routed through the non-root boundary helper');
assert.match(deployScript, /ensure_env_readable_by_web_user/, 'deploy user env readability must be ensured');
assert.match(deployScript, /prepare_release_for_app_user/, 'deploy user write paths must be prepared');
assert.match(
  deployScript,
  /run_repository_command '\nset -Eeuo pipefail\ncd "\$1"\n"\$COMPOSER_BIN" install/,
  'composer install must run through the non-root boundary',
);
assert.equal((deployScript.match(/"\$COMPOSER_BIN" install/g) || []).length, 1, 'composer install must be invoked exactly once');
assert.match(deployScript, /ARTISAN_BLOCK=/, 'artisan commands must be grouped into one boundary block');
assert.match(deployScript, /run_repository_command "\$ARTISAN_BLOCK"/, 'the artisan group must run through the non-root boundary');
assert.equal((deployScript.match(/"\$PHP_BIN" artisan migrate --force/g) || []).length, 1, 'artisan migrate must be invoked exactly once');
assert.match(deployScript, /--rehearsal/, 'rehearsal dry-run mode must exist');
assert.match(deployScript, /REHEARSAL: skipping production migrations/, 'rehearsal must not run production migrations');
assert.match(deployScript, /REHEARSAL: release prepared but current symlink was NOT switched/, 'rehearsal must not switch current');
assert.match(deployScript, /REHEARSAL PASS/, 'rehearsal must emit a distinct pass result');
assert.match(deployScript, /\[\[ "\$REHEARSAL_MODE" == true \]\]/, 'rehearsal branch must be explicit');
assert.match(deployScript, /chown root:"\$WEB_GROUP" "\$SHARED_ENV"/, 'env ownership fix must keep root as owner');
assert.match(deployScript, /chmod u\+rw,g\+r,o-rwx "\$SHARED_ENV"/, 'env fix must not widen world access');
assert.match(runbook, /mutex/i, 'runbook must document the deployment mutex');
assert.match(runbook, /non-root/, 'runbook must document the non-root execution boundary');
assert.match(runbook, /--rehearsal/, 'runbook must document rehearsal mode');

console.log('PASS production deployment contract');
