// k6 skeleton: a short smoke run of GET /api/v1/health through edge.
//   docker compose --profile tools run --rm k6 run /work/tests/load/smoke.js
// Thresholds make k6 exit non-zero when the API is not healthy.
import { check } from 'k6';
import { Counter } from 'k6/metrics';
import { int } from './lib/config.js';
import { get, json } from './lib/http.js';
import { counter, report } from './lib/summary.js';

const healthOk = new Counter('health_ok');

export const options = {
  scenarios: {
    smoke: {
      executor: 'constant-vus',
      vus: int('VUS', 2),
      duration: `${int('DURATION_S', 10)}s`,
    },
  },
  thresholds: {
    checks: ['rate==1'],
    http_req_failed: ['rate==0'],
    // Health is a trivial endpoint; anything slower points at the stack.
    http_req_duration: ['p(95)<500'],
  },
};

export default function () {
  const response = get('/health', { tags: { name: 'health' } });
  const body = json(response);
  const ok = check(response, {
    'status is 200': (r) => r.status === 200,
    'has X-Request-Id': (r) => Boolean(r.headers['X-Request-Id']),
    'body.status is ok': () => body !== null && body.status === 'ok',
    'body.checks.db is ok': () => body !== null && body.checks && body.checks.db === 'ok',
  });
  if (ok) healthOk.add(1);
}

export function handleSummary(data) {
  const requests = counter(data, 'http_reqs');
  const lines = [
    `requests: ${requests}`,
    `healthy answers: ${counter(data, 'health_ok')}`,
    `p95 duration: ${data.metrics.http_req_duration ? data.metrics.http_req_duration.values['p(95)'].toFixed(1) : 'n/a'} ms`,
  ];
  return { stdout: report('k6 smoke: GET /api/v1/health', lines, data) };
}
