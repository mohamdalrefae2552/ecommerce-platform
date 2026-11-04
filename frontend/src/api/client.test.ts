import { HttpResponse, delay, http } from 'msw';
import { describe, expect, it } from 'vitest';
import { endSession, getSession, startSession } from '../auth/session';
import { customer as mockUser, db, issueToken, problem } from '../mocks/db';
import { apiUrl, healthOk, problemResponse } from '../mocks/handlers';
import { server } from '../mocks/server';
import { API_PATH, api, createApiClient, requireData, restoreSession } from './client';
import {
  NetworkError,
  ProblemError,
  RequestTimeoutError,
  UnexpectedResponseError,
  networkErrorMessage,
  problemMessages,
  requestTimeoutMessage,
  unexpectedResponseMessage,
} from './problem';

function setAccessToken(token: string | null): void {
  if (token === null) {
    endSession();
  } else {
    startSession({ access_token: token, token_type: 'Bearer', expires_in: 900, user: mockUser });
  }
}

const EDGE_REQUEST_ID = /^[A-Za-z0-9._-]{1,128}$/;
const TRACE_ID = '01JC3Z9A1B2C3D4E5F6G7H8J9K';

function captureHeaders(): Headers[] {
  const seen: Headers[] = [];
  server.use(
    http.get(apiUrl('/auth/me'), ({ request }) => {
      seen.push(request.headers);
      return HttpResponse.json({ data: mockUser });
    }),
  );
  return seen;
}

async function rejectionOf(promise: Promise<unknown>): Promise<unknown> {
  try {
    await promise;
  } catch (error) {
    return error;
  }
  throw new Error('expected the call to be rejected');
}

describe('api client — request headers', () => {
  it('sends the in-memory access token as a Bearer Authorization header', async () => {
    const seen = captureHeaders();
    setAccessToken('header.payload.signature');

    await api.GET('/auth/me');

    expect(seen[0]?.get('Authorization')).toBe('Bearer header.payload.signature');
  });

  it('sends no Authorization header while logged out', async () => {
    const seen = captureHeaders();

    await api.GET('/auth/me');

    expect(seen[0]?.has('Authorization')).toBe(false);
  });

  it('reads the token on every request, so logging out takes effect immediately', async () => {
    const seen = captureHeaders();
    setAccessToken('first');
    await api.GET('/auth/me');
    setAccessToken(null);
    await api.GET('/auth/me');

    expect(seen.map((h) => h.get('Authorization'))).toEqual(['Bearer first', null]);
  });

  it('sends a fresh X-Request-Id accepted by edge on every request', async () => {
    const seen = captureHeaders();

    await api.GET('/auth/me');
    await api.GET('/auth/me');

    const ids = seen.map((h) => h.get('X-Request-Id'));
    expect(ids).toHaveLength(2);
    for (const id of ids) expect(id).toMatch(EDGE_REQUEST_ID);
    expect(ids[0]).not.toBe(ids[1]);
  });

  it('uses the injected request-id generator', async () => {
    const seen = captureHeaders();
    const client = createApiClient({
      baseUrl: new URL(API_PATH, window.location.origin).href,
      getAccessToken: () => null,
      createRequestId: () => 'fixed-id.1',
    });

    await client.GET('/auth/me');

    expect(seen[0]?.get('X-Request-Id')).toBe('fixed-id.1');
  });
});

describe('api client — successful responses', () => {
  it('returns the typed body through requireData', async () => {
    const health = requireData(await api.GET('/health'));

    expect(health).toEqual(healthOk);
  });

  it('treats a 2xx without a body as unexpected in requireData', async () => {
    server.use(http.get(apiUrl('/health'), () => new HttpResponse(null, { status: 204 })));

    const error = await rejectionOf(api.GET('/health').then(requireData));

    expect(error).toBeInstanceOf(UnexpectedResponseError);
  });
});

describe('api client — problem+json mapping', () => {
  it('throws a typed ProblemError with the Arabic message for the code', async () => {
    server.use(
      http.get(apiUrl('/auth/me'), () =>
        problemResponse({
          type: 'https://ecommerce.local/problems/not-found',
          title: 'Not found',
          status: 404,
          detail: 'المورد المطلوب غير موجود.',
          instance: '/api/v1/auth/me',
          code: 'NOT_FOUND',
          trace_id: TRACE_ID,
        }),
      ),
    );

    const error = await rejectionOf(api.GET('/auth/me'));

    expect(error).toBeInstanceOf(ProblemError);
    if (!(error instanceof ProblemError)) return;
    expect(error.code).toBe('NOT_FOUND');
    expect(error.status).toBe(404);
    expect(error.requestId).toBe(TRACE_ID);
    expect(error.message).toBe(problemMessages.NOT_FOUND);
  });

  it('exposes field errors of VALIDATION_FAILED', async () => {
    server.use(
      http.get(apiUrl('/auth/me'), () =>
        problemResponse({
          type: 'https://ecommerce.local/problems/validation-failed',
          title: 'Validation failed',
          status: 422,
          code: 'VALIDATION_FAILED',
          trace_id: TRACE_ID,
          errors: { email: ['البريد الإلكتروني مستخدم مسبقاً.'] },
        }),
      ),
    );

    const error = await rejectionOf(api.GET('/auth/me'));

    expect(error).toBeInstanceOf(ProblemError);
    if (!(error instanceof ProblemError)) return;
    expect(error.fieldErrors).toEqual({ email: ['البريد الإلكتروني مستخدم مسبقاً.'] });
    expect(error.message).toBe(problemMessages.VALIDATION_FAILED);
  });

  it('exposes retry_after of RATE_LIMITED', async () => {
    server.use(
      http.get(apiUrl('/auth/me'), () =>
        problemResponse(
          {
            type: 'https://ecommerce.local/problems/rate-limited',
            title: 'Too many requests',
            status: 429,
            code: 'RATE_LIMITED',
            trace_id: TRACE_ID,
            retry_after: 42,
          },
          { 'Retry-After': '42' },
        ),
      ),
    );

    const error = await rejectionOf(api.GET('/auth/me'));

    expect(error).toBeInstanceOf(ProblemError);
    if (!(error instanceof ProblemError)) return;
    expect(error.code).toBe('RATE_LIMITED');
    expect(error.retryAfter).toBe(42);
  });

  it('accepts media-type parameters and edge answers (502 INTERNAL_ERROR)', async () => {
    server.use(
      http.get(apiUrl('/health'), () =>
        HttpResponse.json(
          {
            type: 'https://ecommerce.local/problems/internal-error',
            title: 'Internal error',
            status: 502,
            code: 'INTERNAL_ERROR',
            trace_id: TRACE_ID,
          },
          {
            status: 502,
            headers: { 'Content-Type': 'Application/Problem+JSON; charset=utf-8' },
          },
        ),
      ),
    );

    const error = await rejectionOf(api.GET('/health'));

    expect(error).toBeInstanceOf(ProblemError);
    if (!(error instanceof ProblemError)) return;
    expect(error.status).toBe(502);
    expect(error.message).toBe(problemMessages.INTERNAL_ERROR);
  });
});

describe('api client — responses that break the error contract', () => {
  it('maps a non-problem error (HTML from a proxy) to UnexpectedResponseError', async () => {
    server.use(
      http.get(
        apiUrl('/health'),
        () =>
          new HttpResponse('<html>Bad Gateway</html>', {
            status: 502,
            headers: { 'Content-Type': 'text/html', 'X-Request-Id': TRACE_ID },
          }),
      ),
    );

    const error = await rejectionOf(api.GET('/health'));

    expect(error).toBeInstanceOf(UnexpectedResponseError);
    if (!(error instanceof UnexpectedResponseError)) return;
    expect(error.status).toBe(502);
    expect(error.requestId).toBe(TRACE_ID);
    expect(error.message).toBe(unexpectedResponseMessage);
  });

  it('maps a plain JSON error without the problem media type to UnexpectedResponseError', async () => {
    server.use(
      http.get(apiUrl('/health'), () => HttpResponse.json({ message: 'boom' }, { status: 500 })),
    );

    expect(await rejectionOf(api.GET('/health'))).toBeInstanceOf(UnexpectedResponseError);
  });

  it('rejects a problem whose code is not in the contract', async () => {
    server.use(
      http.get(apiUrl('/health'), () =>
        HttpResponse.json(
          { type: 'x', title: 'x', status: 500, code: 'SOMETHING_NEW', trace_id: TRACE_ID },
          { status: 500, headers: { 'Content-Type': 'application/problem+json' } },
        ),
      ),
    );

    const error = await rejectionOf(api.GET('/health'));

    expect(error).toBeInstanceOf(UnexpectedResponseError);
    if (!(error instanceof UnexpectedResponseError)) return;
    expect(error.reason).toContain('does not match the contract');
  });

  it('rejects a problem body that is not JSON', async () => {
    server.use(
      http.get(
        apiUrl('/health'),
        () =>
          new HttpResponse('{not json', {
            status: 500,
            headers: { 'Content-Type': 'application/problem+json' },
          }),
      ),
    );

    const error = await rejectionOf(api.GET('/health'));

    expect(error).toBeInstanceOf(UnexpectedResponseError);
    if (!(error instanceof UnexpectedResponseError)) return;
    expect(error.reason).toBe('problem body is not JSON');
  });

  it('maps a failed connection to NetworkError', async () => {
    server.use(http.get(apiUrl('/health'), () => HttpResponse.error()));

    const error = await rejectionOf(api.GET('/health'));

    expect(error).toBeInstanceOf(NetworkError);
    if (!(error instanceof NetworkError)) return;
    expect(error.message).toBe(networkErrorMessage);
  });
});

describe('api client — client-side deadline', () => {
  it('maps an elapsed deadline to RequestTimeoutError with a clear message', async () => {
    server.use(
      http.get(apiUrl('/health'), async () => {
        await delay(500);
        return HttpResponse.json(healthOk);
      }),
    );
    const client = createApiClient({
      baseUrl: new URL(API_PATH, window.location.origin).href,
      getAccessToken: () => null,
      timeoutMs: 50,
    });

    const error = await rejectionOf(client.GET('/health'));

    expect(error).toBeInstanceOf(RequestTimeoutError);
    if (!(error instanceof RequestTimeoutError)) return;
    expect(error.message).toBe(requestTimeoutMessage);
  });
});

describe('api client — refresh on TOKEN_EXPIRED', () => {
  function expireCurrentToken(): void {
    const session = getSession();
    if (session.status !== 'authenticated') throw new Error('expected a session');
    db.expiredTokens.add(session.accessToken);
  }

  function countRefreshes(): { count: number } {
    const counter = { count: 0 };
    server.events.on('request:start', ({ request }) => {
      if (request.url.endsWith('/auth/refresh')) counter.count += 1;
    });
    return counter;
  }

  it('renews the token once for concurrent 401s and replays each request once', async () => {
    startSession(issueToken(mockUser.id));
    expireCurrentToken();
    const refreshes = countRefreshes();

    const results = await Promise.all([
      api.GET('/auth/me'),
      api.GET('/auth/me'),
      api.GET('/auth/me'),
    ]);

    expect(refreshes.count).toBe(1);
    expect(results.map((r) => r.data?.data.id)).toEqual([mockUser.id, mockUser.id, mockUser.id]);
    const session = getSession();
    expect(session.status).toBe('authenticated');
    if (session.status === 'authenticated') {
      expect(db.expiredTokens.has(session.accessToken)).toBe(false);
    }
  });

  it('replays a POST with the same body and Idempotency-Key', async () => {
    startSession(issueToken(mockUser.id));
    expireCurrentToken();
    const seen: { key: string | null; body: string; auth: string | null }[] = [];
    server.use(
      http.post(apiUrl('/orders'), async ({ request }) => {
        const body = await request.text();
        seen.push({
          key: request.headers.get('Idempotency-Key'),
          body,
          auth: request.headers.get('Authorization'),
        });
        const token = request.headers.get('Authorization')?.slice('Bearer '.length) ?? '';
        if (db.expiredTokens.has(token)) {
          return problemResponse(problem('TOKEN_EXPIRED', 401, '/api/v1/orders'));
        }
        return problemResponse(problem('NOT_FOUND', 404, '/api/v1/orders'));
      }),
    );
    const key = '3f1c2a9e-6b7d-4c8e-9f10-2a3b4c5d6e7f';

    await rejectionOf(
      api.POST('/orders', {
        params: { header: { 'Idempotency-Key': key } },
        body: { items: [{ product_id: '01JC40A1B2C3D4E5F6G7H8J9KM', quantity: 1 }] },
      }),
    );

    expect(seen).toHaveLength(2);
    expect(seen[0]?.key).toBe(key);
    expect(seen[1]?.key).toBe(key);
    expect(seen[1]?.body).toBe(seen[0]?.body);
    expect(seen[1]?.auth).not.toBe(seen[0]?.auth);
  });

  it('ends the session when the refresh is refused and surfaces the 401', async () => {
    startSession(issueToken(mockUser.id));
    expireCurrentToken();
    db.refreshCookieUser = null;

    const error = await rejectionOf(api.GET('/auth/me'));

    expect(error).toBeInstanceOf(ProblemError);
    if (error instanceof ProblemError) expect(error.code).toBe('TOKEN_EXPIRED');
    expect(getSession().status).toBe('anonymous');
  });

  it('does not refresh on other 401 codes', async () => {
    const refreshes = countRefreshes();
    startSession({
      access_token: 'forged.token.value',
      token_type: 'Bearer',
      expires_in: 900,
      user: mockUser,
    });

    const error = await rejectionOf(api.GET('/auth/me'));

    expect(error).toBeInstanceOf(ProblemError);
    if (error instanceof ProblemError) expect(error.code).toBe('UNAUTHENTICATED');
    expect(refreshes.count).toBe(0);
  });

  it('sends the CSRF header on refresh and restores the session at boot', async () => {
    db.refreshCookieUser = mockUser.id;
    const seen: (string | null)[] = [];
    server.events.on('request:start', ({ request }) => {
      if (request.url.endsWith('/auth/refresh')) seen.push(request.headers.get('X-Requested-With'));
    });

    await restoreSession();

    expect(seen).toEqual(['fetch']);
    const session = getSession();
    expect(session.status).toBe('authenticated');
    if (session.status === 'authenticated') expect(session.user.id).toBe(mockUser.id);
  });

  it('settles as anonymous at boot without a refresh cookie', async () => {
    await restoreSession();

    expect(getSession().status).toBe('anonymous');
  });
});
