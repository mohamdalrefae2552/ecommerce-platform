// Requirement 3 (R3-7, `make perf-test OUT=baseline|after`): latency of the
// most-read endpoints under a steady mixed load, reported as p50/p95/p99 per
// endpoint. Run it once BEFORE any optimisation (baseline) and once after.
//
//   setup():  the admin and the seeded customers log in (20 logins/min per IP:
//             on 429 we wait for Retry-After), and a pool of product ids is
//             read from the first catalogue pages.
//   load:     five constant-arrival-rate scenarios run in parallel:
//               products_list    GET /products?page=N[&category=slug]
//               product_details  GET /products/{id}
//               categories       GET /categories
//               my_orders        GET /orders?page=N as a customer (thousands of orders each)
//               admin_orders     GET /orders?page=N[&status=..] as the admin (every order)
//   output:   text table on stdout + /work/out/<OUT>.json and <OUT>.txt
//             (docs/performance/ through the Makefile mount).
//
// Rate limits are NOT raised for the test (docs/04 section 3: 120 requests per
// minute per user, or per IP when anonymous). All k6 traffic comes from one IP,
// so every request carries a customer's token and the iterations are spread
// round-robin over the customers: each one stays below its own limit, like
// many real shoppers would. Any 429 is counted and fails the run.
//
// Clients revalidate like browsers: each VU remembers the ETag of every URL
// it fetched and sends If-None-Match next time. Before ETag support existed
// the server never sends one, so the baseline is all 200s.
import exec from 'k6/execution';
import { check, sleep } from 'k6';
import { Counter, Rate } from 'k6/metrics';
import { int } from './lib/config.js';
import { expectStatus, get, json, post } from './lib/http.js';
import { counter, report } from './lib/summary.js';

const SEEDED = int('SEED_CUSTOMER_COUNT', 60);
const CUSTOMERS = Math.min(int('CUSTOMERS', SEEDED), SEEDED);
const PASSWORD = __ENV.SEED_CUSTOMER_PASSWORD;
// `make perf-compare` runs this script twice within the 15-minute token
// lifetime: the first run saves the access tokens (TOKENS_OUT, a temp file
// outside the repository) and the second reuses them (TOKENS_IN), so the
// per-IP login limit (20/min) is not paid twice.
const REUSED = __ENV.TOKENS_IN ? JSON.parse(open(__ENV.TOKENS_IN)) : null;
const DURATION = __ENV.DURATION || '60s';
const OUT = __ENV.OUT || 'perf';
// Requests per second of each scenario (total ~91.5/s: 1.5/s per customer
// with 60 customers, under the 2/s = 120/min limit). SCALE multiplies them.
const SCALE = Number(__ENV.SCALE || '1');
const RATES = { products_list: 35, product_details: 30, categories: 10, my_orders: 15, admin_orders: 1.5 };

const ENDPOINTS = Object.keys(RATES);
const rateLimited = new Counter('rate_limited'); // 429: the run is invalid
const notModified = new Rate('not_modified'); // share of 304 answers

function scenario(name) {
  const perSecond = RATES[name] * SCALE;
  // Fractional rates: count per 10 s.
  const rate = Number.isInteger(perSecond) ? perSecond : Math.round(perSecond * 10);
  return {
    executor: 'constant-arrival-rate',
    exec: name,
    rate,
    timeUnit: Number.isInteger(perSecond) ? '1s' : '10s',
    duration: DURATION,
    preAllocatedVUs: Math.max(5, Math.ceil(perSecond)),
    maxVUs: Math.max(20, Math.ceil(perSecond * 4)),
    tags: { endpoint: name },
  };
}

const thresholds = {
  rate_limited: ['count==0'],
  checks: ['rate>0.99'],
};
// One sub-metric per endpoint so the summary has its own percentiles.
for (const name of ENDPOINTS) {
  thresholds[`http_req_duration{endpoint:${name}}`] = ['max>=0'];
  thresholds[`not_modified{endpoint:${name}}`] = ['rate>=0'];
  thresholds[`http_reqs{endpoint:${name}}`] = ['count>=0'];
}

export const options = {
  scenarios: Object.fromEntries(ENDPOINTS.map((name) => [name, scenario(name)])),
  setupTimeout: '15m',
  summaryTrendStats: ['p(50)', 'p(95)', 'p(99)', 'avg', 'max'],
  thresholds,
};

// Login honouring the per-IP limit: wait Retry-After and try again.
function login(email, password) {
  for (let attempt = 1; attempt <= 6; attempt += 1) {
    const response = post('/auth/login', { email, password }, { tags: { name: 'login' } });
    if (response.status === 429) {
      const wait = Number(response.headers['Retry-After']) || 60;
      console.log(`login rate limit hit (429) - waiting ${wait + 1}s as Retry-After says`);
      sleep(wait + 1);
      continue;
    }
    expectStatus(`login ${email}`, response, 200);
    return json(response).data.access_token;
  }
  throw new Error(`login ${email}: still rate limited after 6 attempts`);
}

export function setup() {
  if (!PASSWORD || !__ENV.DEV_ADMIN_PASSWORD) {
    throw new Error('SEED_CUSTOMER_PASSWORD and DEV_ADMIN_PASSWORD must be set (the Makefile passes them from .env)');
  }
  if (CUSTOMERS < 1) throw new Error('need at least one seeded customer');

  const admin = REUSED ? REUSED.admin : login(__ENV.DEV_ADMIN_EMAIL || 'admin@example.test', __ENV.DEV_ADMIN_PASSWORD);
  const customers = REUSED ? REUSED.customers.slice(0, CUSTOMERS) : [];
  for (let n = customers.length + 1; n <= CUSTOMERS; n += 1) {
    customers.push(login(`customer${n}@example.test`, PASSWORD));
  }

  const categories = json(expectStatus('listCategories', get('/categories', { token: admin }), 200)).data.map((c) => c.slug);
  const products = [];
  for (let page = 1; page <= 5; page += 1) {
    const body = json(expectStatus('listProducts', get(`/products?page=${page}&per_page=100`, { token: admin }), 200));
    products.push(...body.data.map((p) => p.id));
  }
  const total = json(expectStatus('listOrders', get('/orders?per_page=1', { token: admin }), 200)).meta.total;
  console.log(`setup: ${customers.length} customers, ${products.length} products in the pool, ${total} orders in total`);

  return { admin, customers, categories, products };
}

// Round-robin over the customers by global iteration number.
function customer(data) {
  return data.customers[exec.scenario.iterationInTest % data.customers.length];
}

function pick(list) {
  return list[Math.floor(Math.random() * list.length)];
}

const etags = {}; // per VU: path -> last ETag

function fetch(path, token, endpoint) {
  const headers = {};
  if (etags[path]) headers['If-None-Match'] = etags[path];
  const response = get(path, { token, headers, tags: { name: endpoint, endpoint } });

  if (response.status === 429) rateLimited.add(1);
  if (response.headers.Etag) etags[path] = response.headers.Etag;
  notModified.add(response.status === 304, { endpoint });
  check(response, { [`${endpoint} 200/304`]: (r) => r.status === 200 || r.status === 304 }, { endpoint });
}

export function products_list(data) {
  const page = 1 + Math.floor(Math.random() * 10);
  const filter = Math.random() < 0.3 ? `&category=${pick(data.categories)}` : '';
  fetch(`/products?page=${page}${filter}`, customer(data), 'products_list');
}

export function product_details(data) {
  fetch(`/products/${pick(data.products)}`, customer(data), 'product_details');
}

export function categories(data) {
  fetch('/categories', customer(data), 'categories');
}

export function my_orders(data) {
  fetch(`/orders?page=${1 + Math.floor(Math.random() * 5)}`, customer(data), 'my_orders');
}

export function admin_orders(data) {
  const status = Math.random() < 0.3 ? `&status=${pick(['pending', 'confirmed', 'shipped', 'delivered', 'cancelled'])}` : '';
  fetch(`/orders?page=${1 + Math.floor(Math.random() * 20)}${status}`, data.admin, 'admin_orders');
}

function ms(value) {
  return value === undefined ? '-' : value.toFixed(1);
}

export function handleSummary(data) {
  const rows = ENDPOINTS.map((name) => {
    const d = data.metrics[`http_req_duration{endpoint:${name}}`];
    const m = data.metrics[`not_modified{endpoint:${name}}`];
    const n = data.metrics[`http_reqs{endpoint:${name}}`];
    const v = d ? d.values : {};
    return {
      endpoint: name,
      requests: n ? n.values.count : 0,
      p50: v['p(50)'],
      p95: v['p(95)'],
      p99: v['p(99)'],
      avg: v.avg,
      max: v.max,
      not_modified_rate: m ? m.values.rate : 0,
    };
  });
  const header = `${'endpoint'.padEnd(16)} ${'p50 ms'.padStart(8)} ${'p95 ms'.padStart(8)} ${'p99 ms'.padStart(8)} ${'avg ms'.padStart(8)} ${'max ms'.padStart(8)} ${'304 %'.padStart(6)} ${'reqs'.padStart(6)}`;
  const lines = [
    `run: ${OUT}, ${DURATION} per scenario, ${CUSTOMERS} customers, scale ${SCALE}`,
    header,
    ...rows.map(
      (r) =>
        `${r.endpoint.padEnd(16)} ${ms(r.p50).padStart(8)} ${ms(r.p95).padStart(8)} ${ms(r.p99).padStart(8)} ${ms(r.avg).padStart(8)} ${ms(r.max).padStart(8)} ${(100 * r.not_modified_rate).toFixed(1).padStart(6)} ${String(r.requests).padStart(6)}`,
    ),
    `http requests: ${counter(data, 'http_reqs')}, failed: ${(100 * (data.metrics.http_req_failed?.values.rate || 0)).toFixed(2)}%, 429: ${counter(data, 'rate_limited')}, dropped iterations (load not offered: no free VU): ${counter(data, 'dropped_iterations')}`,
  ];
  const text = report(`R3 performance (${OUT})`, lines, data);

  const result = {
    run: OUT,
    finished_at: new Date().toISOString(),
    duration: DURATION,
    customers: CUSTOMERS,
    scale: SCALE,
    rates_per_second: RATES,
    endpoints: rows,
    http_reqs: counter(data, 'http_reqs'),
    http_req_failed_rate: data.metrics.http_req_failed?.values.rate || 0,
    rate_limited: counter(data, 'rate_limited'),
    dropped_iterations: counter(data, 'dropped_iterations'),
  };

  const files = {
    stdout: text,
    [`/work/out/${OUT}.json`]: JSON.stringify(result, null, 2),
    [`/work/out/${OUT}.txt`]: text,
  };
  if (__ENV.TOKENS_OUT && data.setup_data) {
    files[__ENV.TOKENS_OUT] = JSON.stringify({ admin: data.setup_data.admin, customers: data.setup_data.customers });
  }
  return files;
}
