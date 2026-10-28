// Runs browser test cases in waves: the cases of one wave run in parallel (each in its own
// Playwright process and Chrome window), waves run one after another, and the run stops
// after a wave that has a failure. The wave plan explains which cases may run together.
// A wave marked "serial": true runs its cases one after another in a single browser session
// (in spec order), for cases that must not overlap; it saves a sign-in per case.
//
//   node tests/e2e/run-cases.mjs                         all waves of tests/e2e/pricing.plan.json
//   node tests/e2e/run-cases.mjs --from 4                start at wave 4 (1-based)
//   node tests/e2e/run-cases.mjs --only 3                run wave 3 only
//   node tests/e2e/run-cases.mjs "RT-05,RT-06" "RT-08"   ad-hoc waves (comma-separated case IDs)
//   options: --plan <file>  --workers <n> (default 4)  --headless
//
// LPG_SLOWMO (default 250) slows each browser action so the run can be watched.
import { spawn } from 'node:child_process';
import { readFileSync } from 'node:fs';

const args = process.argv.slice(2);
const option = (name, fallback) => {
  const i = args.indexOf(name);
  if (i === -1) return fallback;
  const value = args[i + 1];
  args.splice(i, 2);
  return value;
};
const flag = (name) => {
  const i = args.indexOf(name);
  if (i === -1) return false;
  args.splice(i, 1);
  return true;
};

const planFile = option('--plan', 'tests/e2e/pricing.plan.json');
const workers = Math.max(1, Number(option('--workers', '4')));
const from = Number(option('--from', '1'));
const only = Number(option('--only', '0'));
const headless = flag('--headless');
const plan = JSON.parse(readFileSync(planFile, 'utf8'));

let waves = args.length
  ? args.map((a, i) => ({ name: 'Wave ' + (i + 1), cases: a.split(',').map((s) => s.trim()).filter(Boolean) }))
  : plan.waves;
const numbered = waves.map((w, i) => ({ ...w, number: i + 1 }));
waves = only ? numbered.filter((w) => w.number === only) : numbered.filter((w) => w.number >= from);

/**
 * Runs one case, or several cases in one session, in its own Playwright process.
 * Resolves to { id, passed, seconds, output }.
 */
function runCases(ids) {
  const started = Date.now();
  return new Promise((resolve) => {
    // One command string (case IDs are plain letters, digits and dashes) so the shell can find npx on Windows.
    for (const id of ids) if (!/^[A-Z0-9-]+$/i.test(id)) throw new Error('Invalid case ID: ' + id);
    const grep = '\\b(' + ids.join('|') + ')\\b';
    const command = `npx playwright test ${plan.spec} -g "${grep}" --reporter=line${headless ? '' : ' --headed'}`;
    const child = spawn(command, {
      shell: true,
      env: { ...process.env, LPG_SLOWMO: process.env.LPG_SLOWMO ?? '250', LPG_OUTPUT_DIR: `writable/playwright-results/${ids[0]}` },
    });
    let output = '';
    child.stdout.on('data', (d) => { output += d; });
    child.stderr.on('data', (d) => { output += d; });
    child.on('close', () => {
      const clean = output.replace(/\x1b\[[0-9;]*[A-Za-z]/g, '');
      const passed = new RegExp(`\\b${ids.length} passed\\b`).test(clean) && !/\b(failed|skipped|did not run)\b/.test(clean);
      resolve({ id: ids.join(', '), passed, seconds: Math.round((Date.now() - started) / 1000), output: clean });
    });
  });
}

/** Runs the cases of one wave with at most `workers` at a time, or all in one session when serial. */
async function runWave(cases, serial) {
  const results = [];
  const report = (r) => { console.log(`  ${r.passed ? 'PASS' : 'FAIL'} ${r.id} (${r.seconds}s)`); results.push(r); };
  if (serial) {
    report(await runCases(cases));
    return results;
  }
  let next = 0;
  const lane = async () => {
    while (next < cases.length) report(await runCases([cases[next++]]));
  };
  await Promise.all(Array.from({ length: Math.min(workers, cases.length) }, lane));
  return results;
}

const startedAll = Date.now();
for (const wave of waves) {
  console.log(`Wave ${wave.number}: ${wave.name}${wave.serial ? ' (one session, in order)' : ''} [${wave.cases.join(', ')}]`);
  const results = await runWave(wave.cases, !!wave.serial);
  const failed = results.filter((r) => !r.passed);
  if (failed.length) {
    for (const f of failed) {
      console.log(`\n----- ${f.id} output -----`);
      console.log(f.output.split('\n').filter((l) => l.trim()).slice(-40).join('\n'));
    }
    console.log(`\nStopped after wave ${wave.number}: ${failed.length} run(s) failed. Fix, then resume with --from ${wave.number}.`);
    process.exit(1);
  }
}
const total = waves.reduce((n, w) => n + w.cases.length, 0);
console.log(`\nALL PASSED: ${total} case(s) in ${Math.round((Date.now() - startedAll) / 1000)}s`);
