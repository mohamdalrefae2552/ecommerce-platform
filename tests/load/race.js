// PROOF-R2-1 (`make race-test`): N customers order the LAST unit of a product
// at the same instant; exactly ONE order may succeed (docs/05, R2-F1).
//
//   setup():  the admin creates a "DEMO race last unit" product with stock 1,
//             then N seeded customers log in (20 logins/min per IP: on 429 we
//             wait for Retry-After instead of failing) and one wall-clock
//             start instant is chosen.
//   default:  every VU sleeps until that instant (tests/load/lib/barrier.js),
//             then POSTs /orders for quantity 1 with its own Idempotency-Key.
//   result:   thresholds require exactly one 201 and no answer other than
//             409 INSUFFICIENT_STOCK for the rest. race-verify.sh then checks
//             the final state through the API.
import encoding from 'k6/encoding';
import http from 'k6/http';
import { sleep } from 'k6';
import { Counter } from 'k6/metrics';
import { API_URL, int } from './lib/config.js';
import { startAt, waitFor } from './lib/barrier.js';
import { expectStatus, get, json, post, problemCode } from './lib/http.js';
import { counter, report } from './lib/summary.js';

const SEEDED = int('SEED_CUSTOMER_COUNT', 60);
const N = Math.min(int('N', 50), SEEDED);
const PASSWORD = __ENV.SEED_CUSTOMER_PASSWORD;

const created = new Counter('orders_created'); // 201
const outOfStock = new Counter('orders_insufficient_stock'); // 409 INSUFFICIENT_STOCK
const unexpected = new Counter('orders_unexpected'); // anything else

export const options = {
  scenarios: {
    race: {
      executor: 'per-vu-iterations',
      vus: N,
      iterations: 1,
      maxDuration: '60s',
    },
  },
  setupTimeout: '15m',
  thresholds: {
    orders_created: ['count==1'],
    orders_insufficient_stock: [`count==${N - 1}`],
    orders_unexpected: ['count==0'],
  },
};

// A valid 8x8 PNG (re-encoded to WebP by the API).
const PNG = encoding.b64decode(
  'iVBORw0KGgoAAAANSUhEUgAAAAgAAAAICAIAAABLbSncAAAAaklEQVR4nAXBgQAAQQgAsMc5iCCCCCKIIIIIYli/feKJEClKtBix4j75ZMiUJVuOXHmfeipUqlKtRq26Tz8dOnXp1qNX32eeCZOmTJsxa+6zz4ZNW7bt2LX3uefCpSvXbty6+3gESdEMy/0yoG4Bl9QeDQAAAABJRU5ErkJggg==',
);

function uuid() {
  const hex = () => Math.floor(Math.random() * 16).toString(16);
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => (c === 'x' ? hex() : ((Math.random() * 4) | 8).toString(16)));
}

// Login honouring the per-IP limit: wait Retry-After and try again.
function login(email, password) {
  for (let attempt = 1; attempt <= 6; attempt += 1) {
    const response = post('/auth/login', { email, password }, { tags: { name: 'login' } });
    if (response.status === 429) {
      const wait = Number(response.headers['Retry-After']) || 60;
      console.log(`login rate limit hit (429) — waiting ${wait + 1}s as Retry-After says`);
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
  if (N < 2) throw new Error(`need at least 2 customers to race (N=${N}, SEED_CUSTOMER_COUNT=${SEEDED})`);

  const admin = login(__ENV.DEV_ADMIN_EMAIL || 'admin@example.test', __ENV.DEV_ADMIN_PASSWORD);
  const authorization = { headers: { Authorization: `Bearer ${admin}`, Accept: 'application/json' } };

  const categories = expectStatus('listCategories', get('/categories'), 200);
  const category = json(categories).data[0].id;
  const product = expectStatus(
    'createProduct',
    http.post(
      `${API_URL}/products`,
      {
        name: `DEMO race last unit ${Date.now()}`,
        description: 'Last unit, raced for by concurrent customers (make race-test).',
        price_minor: '100000',
        category_id: category,
        initial_stock: '1',
        image: http.file(PNG, 'demo.png', 'image/png'),
      },
      { ...authorization, tags: { name: 'createProduct' } },
    ),
    201,
  );
  const productId = json(product).data.id;
  const stock = json(expectStatus('getProduct', get(`/products/${productId}`), 200)).data.stock;
  if (stock.available !== 1) throw new Error(`expected stock 1 before the race, got ${JSON.stringify(stock)}`);

  const tokens = [];
  for (let n = 1; n <= N; n += 1) tokens.push(login(`customer${n}@example.test`, PASSWORD));

  // Lead time covers VU wake-up; every VU is already initialised by now.
  return { productId, tokens, at: startAt(3000) };
}

export default function (data) {
  const token = data.tokens[__VU - 1];
  const late = waitFor(data.at);
  const response = post(
    '/orders',
    { items: [{ product_id: data.productId, quantity: 1 }] },
    { token, headers: { 'Idempotency-Key': uuid() }, tags: { name: 'createOrder' } },
  );
  if (response.status === 201) {
    created.add(1);
  } else if (response.status === 409 && problemCode(response) === 'INSUFFICIENT_STOCK') {
    outOfStock.add(1);
  } else {
    unexpected.add(1);
    console.error(`VU ${__VU}: unexpected ${response.status} ${problemCode(response) || ''} (late ${late} ms)`);
  }
}

export function handleSummary(data) {
  const lines = [
    `customers racing at the same instant: ${N}`,
    `stock before the race: 1 unit`,
    `201 Created (order accepted):        ${counter(data, 'orders_created')}   (expected 1)`,
    `409 INSUFFICIENT_STOCK (rejected):   ${counter(data, 'orders_insufficient_stock')}   (expected ${N - 1})`,
    `anything else:                       ${counter(data, 'orders_unexpected')}   (expected 0)`,
    `order latency p95: ${data.metrics.http_req_duration ? data.metrics.http_req_duration.values['p(95)'].toFixed(0) : 'n/a'} ms`,
  ];
  return { stdout: report('PROOF-R2-1: concurrent orders on the last unit', lines, data) };
}
