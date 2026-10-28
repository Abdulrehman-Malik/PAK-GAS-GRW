// Playwright browser tests (tests/e2e). Used by `npx playwright test` and the
// Playwright VS Code extension. Local runs use the installed Google Chrome and a
// short slow-motion delay so each step can be watched; CI keeps its defaults.
import { defineConfig } from '@playwright/test';
import { existsSync, readFileSync } from 'node:fs';

const ci = !!process.env.CI;

// App URL: LPG_BASE_URL, else app.baseURL from the local .env (the same URL the app uses).
// It must end with "/" and tests use relative paths ("sales", not "/sales") so the
// sub-folder (e.g. /lpg-management-system/public/) is kept.
function appBaseUrl() {
  let url = process.env.LPG_BASE_URL || '';
  if (!url && existsSync('.env')) {
    const m = readFileSync('.env', 'utf8').match(/^\s*app\.baseURL\s*=\s*['"]?([^'"\r\n]+)['"]?/m);
    if (m) url = m[1].trim();
  }
  url = url || 'http://localhost:8080/';
  return url.endsWith('/') ? url : url + '/';
}

export default defineConfig({
  testDir: './tests/e2e',
  fullyParallel: false,
  workers: 1,
  timeout: 120_000,
  expect: { timeout: 15_000 },
  reporter: ci ? 'list' : [['list'], ['html', { open: 'never', outputFolder: 'writable/playwright-report' }]],
  // The wave runner (tests/e2e/run-cases.mjs) gives each parallel case its own folder,
  // because Playwright empties the output folder when a run starts.
  outputDir: process.env.LPG_OUTPUT_DIR || 'writable/playwright-results',
  use: {
    baseURL: appBaseUrl(),
    channel: ci ? undefined : (process.env.LPG_BROWSER_CHANNEL || 'chrome'),
    viewport: { width: 1440, height: 900 },
    launchOptions: { slowMo: Number(process.env.LPG_SLOWMO ?? (ci ? 0 : 250)) },
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
  },
});
