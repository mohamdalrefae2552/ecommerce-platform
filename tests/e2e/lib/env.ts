/**
 * Runtime configuration of the harness. The defaults are the values compose
 * passes to the `e2e` service (and `.env.example`): the container shares
 * edge's network namespace, so the app is plain http://localhost — the only
 * plain-http origin where browsers still send the `Secure` refresh cookie.
 * Override both when running from the host, e.g.
 *   EDGE_URL=http://localhost:8080 TOXIPROXY_URL=http://localhost:8474
 */
function url(name: string, fallback: string): string {
  const raw = process.env[name]?.trim() || fallback;
  try {
    return new URL(raw).toString().replace(/\/$/, '');
  } catch {
    throw new Error(`${name} is not a valid absolute URL: "${raw}"`);
  }
}

export const env = {
  edgeUrl: url('EDGE_URL', 'http://localhost'),
  toxiproxyUrl: url('TOXIPROXY_URL', 'http://toxiproxy:8474'),
  /** Deadline of each Toxiproxy admin call; generous because CI runners can be slow. */
  toxiproxyTimeoutMs: Number(process.env.TOXIPROXY_TIMEOUT_MS ?? 10_000),
  ci: Boolean(process.env.CI),
} as const;

/** Every REST path lives under this prefix (R1-A1). */
export const API_PREFIX = '/api/v1';
