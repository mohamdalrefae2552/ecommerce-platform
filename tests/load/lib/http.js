// JSON helpers over k6/http for the REST contract (contracts/openapi/openapi.yaml).
import http from 'k6/http';
import { API_URL, REQUEST_TIMEOUT } from './config.js';

// Unique per VU and iteration: also used as X-Request-Id so a request can be
// found in the edge/api logs.
export function requestId(prefix = 'k6') {
  // __VU/__ITER do not exist in setup(), where the load scripts also call this.
  const vu = typeof __VU === 'undefined' ? 0 : __VU;
  const iter = typeof __ITER === 'undefined' ? 0 : __ITER;
  return `${prefix}-${vu}-${iter}-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
}

function params({ token, headers = {}, tags = {} } = {}) {
  const all = { Accept: 'application/json', 'X-Request-Id': requestId(), ...headers };
  if (token) all.Authorization = `Bearer ${token}`;
  return { headers: all, tags, timeout: REQUEST_TIMEOUT };
}

// get('/health', { token, headers, tags })
export function get(path, options) {
  return http.get(`${API_URL}${path}`, params(options));
}

// post('/orders', body, { token, headers, tags })
export function post(path, body, options = {}) {
  const p = params(options);
  p.headers['Content-Type'] = 'application/json';
  return http.post(`${API_URL}${path}`, JSON.stringify(body), p);
}

// Parsed JSON body, or null when the body is not JSON.
export function json(response) {
  try {
    return response.json();
  } catch (_) {
    return null;
  }
}

// The RFC 9457 `code` of a problem response, or null.
export function problemCode(response) {
  const contentType = response.headers['Content-Type'] || '';
  if (!contentType.startsWith('application/problem+json')) return null;
  const body = json(response);
  return body && typeof body.code === 'string' ? body.code : null;
}

// Throws (aborting setup) when the status is not the expected one.
export function expectStatus(operation, response, expected) {
  if (response.status !== expected) {
    const code = problemCode(response);
    throw new Error(
      `${operation} -> HTTP ${response.status}${code ? ` ${code}` : ''} (expected ${expected}): ${String(response.body).slice(0, 300)}`,
    );
  }
  return response;
}
