import { HttpResponse, http } from 'msw';
import type { Problem } from '../api/problem';
import type { components } from '../api/schema';

type Schemas = components['schemas'];

/** Absolute so the same handlers serve the browser worker and the Node test server. */
export const apiUrl = (path: `/${string}`): string =>
  new URL(`/api/v1${path}`, window.location.origin).href;

const MOCK_REQUEST_ID = '01JC3Z9A1B2C3D4E5F6G7H8J9K';

/** A problem+json response shaped exactly like the contract's `Problem`. */
export function problemResponse(problem: Problem, headers: Record<string, string> = {}) {
  return HttpResponse.json(problem, {
    status: problem.status,
    headers: {
      'Content-Type': 'application/problem+json',
      'X-Request-Id': problem.trace_id,
      ...headers,
    },
  });
}

export const healthOk: Schemas['Health'] = { status: 'ok', checks: { db: 'ok', inventory: 'ok' } };

export const mockUser: Schemas['User'] = {
  id: '01JC3Z8X7Q4M2N5P6R7S8T9V0W',
  name: 'Lina Haddad',
  email: 'lina@example.com',
  role: 'customer',
  created_at: '2026-11-01T17:41:00Z',
};

/** Contract examples for the endpoints the skeleton uses; features add their own. */
export const handlers = [
  http.get(apiUrl('/health'), () =>
    HttpResponse.json(healthOk, { headers: { 'X-Request-Id': MOCK_REQUEST_ID } }),
  ),

  http.get(apiUrl('/auth/me'), ({ request }) => {
    if (request.headers.get('Authorization') === null) {
      return problemResponse(
        {
          type: 'https://ecommerce.local/problems/unauthenticated',
          title: 'Unauthenticated',
          status: 401,
          detail: 'يجب تسجيل الدخول للوصول إلى هذا المورد.',
          instance: '/api/v1/auth/me',
          code: 'UNAUTHENTICATED',
          trace_id: MOCK_REQUEST_ID,
        },
        { 'WWW-Authenticate': 'Bearer' },
      );
    }
    const body: Schemas['UserEnvelope'] = { data: mockUser };
    return HttpResponse.json(body, { headers: { 'X-Request-Id': MOCK_REQUEST_ID } });
  }),
];
