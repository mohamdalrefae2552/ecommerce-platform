// Requirement 5 (PROOF-R5-1, PROOF-R5-2): REQUESTS requests (500 by default)
// at a steady rate against the api replicas behind edge. Every request carries
// X-Request-Id "<RUN>-<n>" so infra/scripts/scale-load.sh can read from the
// edge access log which replica answered it.
//
// The general limit stays as it is (120 requests per minute per user): the
// requests carry the tokens of 60 seeded customers, round-robin, like many
// shoppers would. Tokens come from `php artisan app:perf-tokens` (TOKENS_IN).
//
// Mix: 60% catalogue page, 25% product details, 15% "my orders".
import exec from 'k6/execution';
import { check } from 'k6';
import { Counter } from 'k6/metrics';
import { int } from './lib/config.js';
import { expectStatus, get, json } from './lib/http.js';
import { counter, report } from './lib/summary.js';

const REQUESTS = int('REQUESTS', 500);
const RATE = int('RATE', 25);
const RUN = __ENV.RUN || `scale-${Date.now()}`;
const TOKENS = JSON.parse(open(__ENV.TOKENS_IN));

const failed = new Counter('failed_requests');
const rateLimited = new Counter('rate_limited');

export const options = {
  scenarios: {
    load: {
      executor: 'constant-arrival-rate',
      rate: RATE,
      timeUnit: '1s',
      duration: `${Math.ceil(REQUESTS / RATE)}s`,
      preAllocatedVUs: RATE * 2,
      maxVUs: RATE * 8,
    },
  },
  summaryTrendStats: ['p(50)', 'p(95)', 'max'],
  thresholds: { failed_requests: ['count==0'], rate_limited: ['count==0'] },
};

export function setup() {
  const products = json(expectStatus('listProducts', get('/products?per_page=100', { token: TOKENS.admin }), 200)).data.map((p) => p.id);
  return { customers: TOKENS.customers, products };
}

export default function (data) {
  const n = exec.scenario.iterationInTest;
  if (n >= REQUESTS) return;
  const token = data.customers[n % data.customers.length];
  const pick = n % 20;
  let path;
  if (pick < 12) path = `/products?page=${(n % 9) + 1}`;
  else if (pick < 17) path = `/products/${data.products[n % data.products.length]}`;
  else path = '/orders?per_page=10';

  const response = get(path, { token, headers: { 'X-Request-Id': `${RUN}-${n}` } });
  if (response.status === 429) rateLimited.add(1);
  const ok = check(response, { 'answered 200/304': (r) => r.status === 200 || r.status === 304 });
  if (!ok) failed.add(1);
}

export function handleSummary(data) {
  const total = counter(data, 'http_reqs') - 2; // minus the setup request and k6's own
  const lines = [
    `run ${RUN}: ${REQUESTS} requests at ${RATE}/s from ${TOKENS.customers.length} customers`,
    `failed (not 200/304): ${counter(data, 'failed_requests')}, rate limited (429): ${counter(data, 'rate_limited')}`,
    `latency ms: p50 ${data.metrics.http_req_duration.values['p(50)'].toFixed(1)} · p95 ${data.metrics.http_req_duration.values['p(95)'].toFixed(1)} · max ${data.metrics.http_req_duration.values.max.toFixed(1)}`,
  ];
  void total;
  return { stdout: report('R5 load', lines, data) };
}
