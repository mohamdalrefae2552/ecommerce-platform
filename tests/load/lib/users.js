// Test users created through the public API (used in setup(), outside the
// measured scenario).
import { expectStatus, json, post } from './http.js';

function suffix() {
  return `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 10)}`;
}

// POST /auth/register then POST /auth/login. Returns { id, email, password, token }.
// Note: register is limited to 5 per hour per IP (docs/04 section 3).
export function createCustomer(label = 'k6') {
  const id = suffix();
  const credentials = {
    name: `K6 ${label} ${id}`,
    email: `k6-${label}-${id}@example.test`,
    password: `pw-${id}-${suffix()}`,
  };

  const registered = expectStatus(
    'registerUser',
    post('/auth/register', { ...credentials, password_confirmation: credentials.password }, { tags: { name: 'register' } }),
    201,
  );
  const user = json(registered).data;

  return { id: user.id, email: credentials.email, password: credentials.password, token: login(credentials.email, credentials.password) };
}

// POST /auth/login -> access token.
export function login(email, password) {
  const response = expectStatus('loginUser', post('/auth/login', { email, password }, { tags: { name: 'login' } }), 200);
  return json(response).data.access_token;
}
