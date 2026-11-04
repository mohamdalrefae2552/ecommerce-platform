/**
 * Smoke test of the whole stack through `edge` (P0-QA-01 acceptance): the SPA
 * shell loads and mounts under its strict CSP, and the API health endpoint
 * answers exactly as the contract says (operationId getHealth).
 */
import { api, type Health } from '../lib/api';
import { expect, test } from '../fixtures';

test.describe('smoke', () => {
  test('home page loads, mounts the app and has no console or CSP errors', async ({ page }) => {
    const errors: string[] = [];
    page.on('console', (message) => {
      if (message.type() === 'error') errors.push(message.text());
    });
    page.on('pageerror', (error) => errors.push(error.message));

    const response = await page.goto('/');
    expect(response, 'navigation produced no response').not.toBeNull();
    expect(response?.status()).toBe(200);
    expect(response?.headers()['content-type']).toContain('text/html');
    expect(response?.headers()['content-security-policy']).toContain("script-src 'self'");

    await expect(page.locator('html')).toHaveAttribute('lang', 'ar');
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    // React rendered something into the root element.
    await expect(page.locator('#root > *').first()).toBeAttached();

    await page.waitForLoadState('networkidle');
    expect(errors, 'console errors on the home page').toEqual([]);
  });

  test('GET /api/v1/health answers 200 with the contract shape', async ({ request }) => {
    const response = await request.get(api('/health'));

    expect(response.status()).toBe(200);
    expect(response.headers()['content-type']).toContain('application/json');
    expect(response.headers()['x-request-id']).toBeTruthy();

    const body = (await response.json()) as Health;
    // Health has additionalProperties: false at both levels: exact keys only.
    expect(body).toEqual({
      status: 'ok',
      checks: { db: 'ok', inventory: expect.stringMatching(/^(ok|degraded)$/) },
    });
  });
});
