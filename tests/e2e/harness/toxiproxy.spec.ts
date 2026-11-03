/**
 * Proves the Toxiproxy helper really changes the network, by measuring it.
 *
 * The test creates its own throw-away proxy whose upstream is Toxiproxy's own
 * admin API (127.0.0.1:8474 inside the toxiproxy container), so it needs no
 * other service and never touches the `inventory` proxy used by the specs.
 * Traffic goes  test → toxiproxy:<SELFTEST_PORT> (toxics applied) → admin API.
 */
import { expect, test } from '@playwright/test';
import { timedGet } from '../lib/probe';
import { Toxiproxy } from '../lib/toxiproxy';

const PROXY = 'e2e-harness-selftest';
const SELFTEST_PORT = Number(process.env.TOXIPROXY_SELFTEST_PORT ?? 18474);
/** Fixed cost allowed on top of baseline + injected delay (scheduling, slow Docker networking). */
const SLACK_MS = 1_500;
/** Upper bound for an un-toxic round trip; above it the link is not "clean". */
const CLEAN_MS = 1_000;

const toxiproxy = new Toxiproxy();
const target = `http://${toxiproxy.host}:${SELFTEST_PORT}/version`;
/** Slowest of a few clean round trips, measured before any toxic is added. */
let baselineMs = 0;

/** Shows a measured duration in the report (list and HTML) as evidence. */
function record(label: string, elapsedMs: number): void {
  test.info().annotations.push({ type: label, description: `${elapsedMs} ms` });
}

test.describe.configure({ mode: 'serial' });

test.beforeAll(async () => {
  await toxiproxy.deleteProxy(PROXY);
  await toxiproxy.createProxy({ name: PROXY, listen: `0.0.0.0:${SELFTEST_PORT}`, upstream: '127.0.0.1:8474' });
  // Warm-up: the first connection to a new listener can be slow (Docker port forwarding).
  await timedGet(target);
});

test.afterAll(async () => {
  await toxiproxy.deleteProxy(PROXY);
});

test('talks to the Toxiproxy admin API', async () => {
  expect(await toxiproxy.version()).toMatch(/^\d+\.\d+\.\d+/);
  const proxy = await toxiproxy.getProxy(PROXY);
  expect(proxy).toMatchObject({ name: PROXY, enabled: true, toxics: [] });
});

test('without toxics the proxied call is fast', async () => {
  for (let i = 0; i < 3; i++) {
    const result = await timedGet(target);
    expect(result.ok, JSON.stringify(result)).toBe(true);
    expect(result.ok && result.status).toBe(200);
    baselineMs = Math.max(baselineMs, result.elapsedMs);
  }
  record('baseline (max of 3)', baselineMs);
  expect(baselineMs).toBeLessThan(CLEAN_MS);
});

test('latency() delays the response by at least the requested time', async () => {
  const toxic = await toxiproxy.latency(1_000, PROXY);
  expect(toxic).toMatchObject({ type: 'latency', stream: 'downstream', attributes: { latency: 1_000, jitter: 0 } });

  const result = await timedGet(target);
  record('with latency 1000 ms', result.elapsedMs);
  expect(result.ok, JSON.stringify(result)).toBe(true);
  expect(result.elapsedMs).toBeGreaterThanOrEqual(1_000);
  expect(result.elapsedMs).toBeLessThan(1_000 + baselineMs + SLACK_MS);
});

test('latency() replaces the previous value instead of stacking toxics', async () => {
  await toxiproxy.latency(300, PROXY);
  await toxiproxy.latency(2_000, PROXY);
  const proxy = await toxiproxy.getProxy(PROXY);
  expect(proxy.toxics).toHaveLength(1);
  expect(proxy.toxics[0]?.attributes).toMatchObject({ latency: 2_000 });

  const result = await timedGet(target);
  record('with latency 2000 ms', result.elapsedMs);
  expect(result.ok, JSON.stringify(result)).toBe(true);
  expect(result.elapsedMs).toBeGreaterThanOrEqual(2_000);
  expect(result.elapsedMs).toBeLessThan(2_000 + baselineMs + SLACK_MS);
});

test('a client deadline shorter than the latency fires on time', async () => {
  await toxiproxy.latency(3_000, PROXY);
  const result = await timedGet(target, 500);
  record('500 ms deadline vs 3000 ms latency', result.elapsedMs);
  expect(result.ok).toBe(false);
  expect(result.elapsedMs).toBeGreaterThanOrEqual(500);
  expect(result.elapsedMs).toBeLessThan(500 + SLACK_MS);
});

test('down() refuses connections without waiting for the client timeout, up() restores them', async () => {
  await toxiproxy.resetProxy(PROXY);
  const down = await toxiproxy.down(PROXY);
  expect(down.enabled).toBe(false);

  const refused = await timedGet(target, 10_000);
  record('link down', refused.elapsedMs);
  expect(refused.ok, JSON.stringify(refused)).toBe(false);
  expect(refused.elapsedMs).toBeLessThan(5_000);

  await toxiproxy.up(PROXY);
  const restored = await timedGet(target);
  expect(restored.ok, JSON.stringify(restored)).toBe(true);
});

test('resetProxy() removes every toxic and re-enables the link', async () => {
  // A large delay, so "it is gone" is unambiguous even on noisy Docker networking.
  await toxiproxy.latency(5_000, PROXY);
  await toxiproxy.down(PROXY);

  const proxy = await toxiproxy.resetProxy(PROXY);
  expect(proxy).toMatchObject({ enabled: true, toxics: [] });

  const result = await timedGet(target);
  record('after reset (5000 ms toxic removed)', result.elapsedMs);
  expect(result.ok, JSON.stringify(result)).toBe(true);
  expect(result.elapsedMs).toBeLessThan(5_000);
});

test('rejects invalid latency values before calling Toxiproxy', async () => {
  await expect(toxiproxy.latency(-1, PROXY)).rejects.toThrow(RangeError);
  await expect(toxiproxy.latency(1.5, PROXY)).rejects.toThrow(RangeError);
});

test('reports a missing proxy as a ToxiproxyError with the HTTP status', async () => {
  await expect(toxiproxy.getProxy('e2e-does-not-exist')).rejects.toMatchObject({
    name: 'ToxiproxyError',
    status: 404,
  });
});
