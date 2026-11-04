// Start barrier for truly concurrent requests (docs/06 section 3, race.js):
// setup() picks one wall-clock instant, every VU sleeps until it, then all
// fire together. Pair with `executor: 'per-vu-iterations', iterations: 1`.
import { sleep } from 'k6';

// startAt(leadMs): an instant far enough in the future for every VU to be
// initialised and waiting before it passes.
export function startAt(leadMs = 3000) {
  return Date.now() + leadMs;
}

// waitFor(at): block this VU until `at`. Returns how late the VU was (ms) —
// a positive value means the lead time was too short for this VU.
export function waitFor(at) {
  const remaining = at - Date.now();
  if (remaining > 0) sleep(remaining / 1000);
  return Math.max(0, Date.now() - at);
}
