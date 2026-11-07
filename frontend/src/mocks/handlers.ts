import { HttpResponse, http } from 'msw';
import type { Problem } from '../api/problem';
import type { components } from '../api/schema';
import { MOCK_REQUEST_ID, type User, db, issueToken, newId, problem, toPublicUser } from './db';

type Schemas = components['schemas'];

/** Absolute so the same handlers serve the browser worker and the Node test server. */
export const apiUrl = (path: `/${string}`): string =>
  new URL(`/api/v1${path}`, window.location.origin).href;

const headers = { 'X-Request-Id': MOCK_REQUEST_ID };

export function json(body: object, status = 200, extra: Record<string, string> = {}) {
  return HttpResponse.json(body, { status, headers: { ...headers, ...extra } });
}

/** A problem+json response shaped exactly like the contract's `Problem`. */
export function problemResponse(body: Problem, extraHeaders: Record<string, string> = {}) {
  return HttpResponse.json(body, {
    status: body.status,
    headers: {
      'Content-Type': 'application/problem+json',
      'X-Request-Id': body.trace_id,
      ...(body.status === 401 ? { 'WWW-Authenticate': 'Bearer' } : {}),
      ...extraHeaders,
    },
  });
}

export const healthOk: Schemas['Health'] = { status: 'ok', checks: { db: 'ok', inventory: 'ok' } };

type Auth = { user: User } | { response: Response };

/** Bearer check as the API does it: missing/unknown → UNAUTHENTICATED, expired → TOKEN_EXPIRED. */
function authenticate(request: Request, instance: string): Auth {
  const header = request.headers.get('Authorization');
  const token = header?.startsWith('Bearer ') === true ? header.slice('Bearer '.length) : null;
  if (token !== null && db.expiredTokens.has(token)) {
    return { response: problemResponse(problem('TOKEN_EXPIRED', 401, instance)) };
  }
  const userId = token === null ? undefined : db.tokens.get(token);
  const user = userId === undefined ? undefined : db.users.get(userId);
  if (user === undefined) {
    return { response: problemResponse(problem('UNAUTHENTICATED', 401, instance)) };
  }
  return { user: toPublicUser(user) };
}

function validationFailed(instance: string, errors: Record<string, string[]>) {
  return problemResponse(
    problem('VALIDATION_FAILED', 422, instance, { detail: 'البيانات المرسلة غير صالحة.', errors }),
  );
}

const authHandlers = [
  http.post(apiUrl('/auth/register'), async ({ request }) => {
    const body = (await request.json()) as Partial<Schemas['RegisterRequest']>;
    const instance = '/api/v1/auth/register';
    const email = (body.email ?? '').toLowerCase();
    if ([...db.users.values()].some((u) => u.email === email)) {
      return validationFailed(instance, { email: ['البريد الإلكتروني مستخدم مسبقاً.'] });
    }
    const user: User = {
      id: newId(),
      name: body.name ?? '',
      email,
      role: 'customer',
      created_at: '2026-11-03T10:00:00Z',
    };
    db.users.set(user.id, { ...user, password: body.password ?? '' });
    const envelope: Schemas['UserEnvelope'] = { data: user };
    return json(envelope, 201, { Location: apiUrl('/auth/me') });
  }),

  http.post(apiUrl('/auth/login'), async ({ request }) => {
    const body = (await request.json()) as Partial<Schemas['LoginRequest']>;
    const user = [...db.users.values()].find(
      (u) => u.email === body.email?.toLowerCase() && u.password === body.password,
    );
    if (user === undefined) {
      return problemResponse(
        problem('INVALID_CREDENTIALS', 401, '/api/v1/auth/login', {
          detail: 'البريد الإلكتروني أو كلمة المرور غير صحيحة.',
        }),
      );
    }
    const envelope: Schemas['TokenEnvelope'] = { data: issueToken(user.id) };
    return json(envelope);
  }),

  http.post(apiUrl('/auth/refresh'), ({ request }) => {
    const instance = '/api/v1/auth/refresh';
    if (request.headers.get('X-Requested-With') !== 'fetch') {
      return problemResponse(problem('FORBIDDEN', 403, instance));
    }
    if (db.refreshCookieUser === null) {
      return problemResponse(problem('REFRESH_TOKEN_INVALID', 401, instance));
    }
    const envelope: Schemas['TokenEnvelope'] = { data: issueToken(db.refreshCookieUser) };
    return json(envelope);
  }),

  http.post(apiUrl('/auth/logout'), ({ request }) => {
    const auth = authenticate(request, '/api/v1/auth/logout');
    if ('response' in auth) return auth.response;
    db.refreshCookieUser = null;
    for (const [token, userId] of db.tokens) {
      if (userId === auth.user.id) db.tokens.delete(token);
    }
    return new HttpResponse(null, { status: 204, headers });
  }),

  http.get(apiUrl('/auth/me'), ({ request }) => {
    const auth = authenticate(request, '/api/v1/auth/me');
    if ('response' in auth) return auth.response;
    const envelope: Schemas['UserEnvelope'] = { data: auth.user };
    return json(envelope);
  }),
];

export const handlers = [http.get(apiUrl('/health'), () => json(healthOk)), ...authHandlers];
