/**
 * End-to-end tests (M11a, master plan §51): the full MVP flow in a real browser against the local install,
 * with an axe-core accessibility check on every screen. Development tooling only (not deployed).
 *
 *   npm run e2e            (Apache + MySQL running; the global setup starts the job dispatcher)
 */
import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: './e2e',
  timeout: 8 * 60 * 1000,
  expect: { timeout: 30_000 },
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: [['list'], ['html', { open: 'never', outputFolder: 'e2e-report' }]],
  globalSetup: './e2e/global-setup.ts',
  globalTeardown: './e2e/global-teardown.ts',
  use: {
    baseURL: process.env.E2E_BASE_URL ?? 'http://localhost/educloudlab/',
    channel: 'chrome', // the installed Google Chrome: no browser download needed
    headless: true,
    locale: 'es-ES',
    viewport: { width: 1280, height: 900 },
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  outputDir: 'e2e-results',
});
