/**
 * Shared Playwright fixtures. Specs import `test` and `expect` from here.
 *
 *   customer       a fresh customer registered and logged in through the API
 *   makeCustomer   factory for more customers in the same test (e.g. A and B for IDOR)
 *   chaos          Toxiproxy client; the inventory link is restored after the test
 *                  even when it fails, so one test can never break the next
 */
import { test as base, expect, type APIRequestContext } from '@playwright/test';
import { createCustomer, type TestUser } from '../lib/api';
import { INVENTORY_PROXY, Toxiproxy } from '../lib/toxiproxy';

/** A test customer plus the request context that holds its refresh cookie. */
export type Customer = TestUser & { request: APIRequestContext };

interface Fixtures {
  makeCustomer: (label?: string) => Promise<Customer>;
  customer: Customer;
  chaos: Toxiproxy;
}

export const test = base.extend<Fixtures>({
  makeCustomer: async ({ playwright, baseURL }, use) => {
    // One request context per customer: each keeps its own refresh cookie.
    const contexts: APIRequestContext[] = [];
    await use(async (label = 'customer') => {
      const context = await playwright.request.newContext(baseURL ? { baseURL } : {});
      contexts.push(context);
      return { ...(await createCustomer(context, label)), request: context };
    });
    await Promise.all(contexts.map((context) => context.dispose()));
  },

  customer: async ({ makeCustomer }, use) => {
    await use(await makeCustomer('customer'));
  },

  chaos: async ({}, use) => {
    const toxiproxy = new Toxiproxy();
    await toxiproxy.resetProxy(INVENTORY_PROXY);
    try {
      await use(toxiproxy);
    } finally {
      await toxiproxy.resetProxy(INVENTORY_PROXY);
    }
  },
});

export { expect };
