import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';
import { setAccessToken } from '../auth/token';
import { apiUrl, healthOk, mockUser, problemResponse } from '../mocks/handlers';
import { server } from '../mocks/server';
import { API_PATH, api, createApiClient, requireData } from './client';
import {
  NetworkError,
  ProblemError,
  UnexpectedResponseError,
  networkErrorMessage,
  problemMessages,
  unexpectedResponseMessage,
} from './problem';

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
