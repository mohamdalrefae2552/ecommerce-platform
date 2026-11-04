/**
 * Playwright runs against the full compose stack through `edge` (`make up`,
 * then `make verify-e2e`). Nothing is mocked: the browser and the API calls
 * go to EDGE_URL, faults are injected with Toxiproxy (TOXIPROXY_URL).
 *
 * Projects:
 *   harness   self-tests of the harness itself (Toxiproxy helper); needs only Toxiproxy
 *   chromium  the end-to-end specs in ./specs
 */
import { defineConfig, devices } from '@playwright/test';
import { env } from './lib/env';

export default defineConfig({
  forbidOnly: env.ci,
  // A retry would hide a flaky proof; every failure must be looked at.
  retries: 0,
  // One worker: the rate limiters are keyed by IP and every request comes
  // from the same one, and the chaos specs share the single inventory link.
  workers: 1,
  fullyParallel: false,
  timeout: 30_000,
  expect: { timeout: 5_000 },
  reporter: [['list'], ['html', { open: 'never', outputFolder: 'playwright-report' }]],
  outputDir: 'test-results',
  use: {
    baseURL: env.edgeUrl,
    locale: 'ar',
    timezoneId: 'UTC',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'off',
    actionTimeout: 5_000,
    navigationTimeout: 10_000,
  },
  projects: [
    {
      name: 'harness',
      testDir: './harness',
    },
    {
      name: 'chromium',
      testDir: './specs',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
});
