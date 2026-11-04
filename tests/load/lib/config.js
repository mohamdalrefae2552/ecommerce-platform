// Shared configuration of the k6 scripts. k6 runs in the `k6` compose service,
// which shares edge's network namespace, so the default target is
// http://localhost (EDGE_URL is set by compose). From the host: -e EDGE_URL=http://localhost:8080
const raw = (__ENV.EDGE_URL || 'http://localhost').trim();

export const EDGE_URL = raw.replace(/\/+$/, '');
export const API_URL = `${EDGE_URL}/api/v1`;

// Explicit client-side deadline for every request (CLAUDE.md rule 6): longer
// than the API's own inventory budget (3s) so the API's answer is what we see.
export const REQUEST_TIMEOUT = __ENV.REQUEST_TIMEOUT || '10s';

// int(name, fallback): read a whole-number option from -e NAME=...
export function int(name, fallback) {
  const value = __ENV[name];
  if (value === undefined || value === '') return fallback;
  const parsed = Number(value);
  if (!Number.isInteger(parsed) || parsed < 0) {
    throw new Error(`${name} must be a non-negative integer, got "${value}"`);
  }
  return parsed;
}
