import createClient, { type Middleware } from 'openapi-fetch';
import { endSession, getAccessToken, getSession, startSession } from '../auth/session';
import {
  NetworkError,
  ProblemError,
  RequestTimeoutError,
  UnexpectedResponseError,
  isProblemContentType,
  problemSchema,
} from './problem';
import type { paths } from './schema';

export const API_PATH = '/api/v1';

/**
 * Client-side deadline for every call (golden rule 6). The server answers
 * within ~3 s even when the inventory is down (docs/05), so 10 s only fires
 * when the network or edge itself hangs.
 */
export const REQUEST_TIMEOUT_MS = 10_000;

export interface ApiClientOptions {
  /** Absolute base URL, e.g. `https://shop.example/api/v1`. */
  readonly baseUrl: string;
  readonly getAccessToken: () => string | null;
  /**
   * Obtains a new access token after a 401 `TOKEN_EXPIRED`; `null` when the
   * session cannot be renewed. Must be single-flight (`createRefresher`).
   */
  readonly refreshAccessToken?: () => Promise<string | null>;
  /** Correlation id sent as `X-Request-Id` (edge accepts `[A-Za-z0-9._-]{1,128}`). */
  readonly createRequestId?: () => string;
  readonly timeoutMs?: number;
}

async function isTokenExpired(response: Response): Promise<boolean> {
  if (response.status !== 401 || !isProblemContentType(response.headers.get('Content-Type'))) {
    return false;
  }
  try {
    const body: unknown = await response.clone().json();
    const parsed = problemSchema.safeParse(body);
    return parsed.success && parsed.data.code === 'TOKEN_EXPIRED';
  } catch {
    return false;
  }
}

/**
 * Adds `Authorization: Bearer <token>` (when logged in) and a fresh
 * `X-Request-Id`. On 401 `TOKEN_EXPIRED` it renews the token once (shared by
 * every concurrent caller) and replays the request once with the new token.
 * Replaying is safe for any method: the server rejected the request before
 * running it, and the copy keeps the same body and Idempotency-Key.
 */
export function authMiddleware(
  readToken: () => string | null,
  createRequestId: () => string,
  refreshAccessToken: (() => Promise<string | null>) | undefined,
): Middleware {
  const replayCopies = new WeakMap<Request, Request>();

  const withHeaders = (request: Request, token: string | null): Request => {
    request.headers.set('X-Request-Id', createRequestId());
    if (token === null) {
      request.headers.delete('Authorization');
    } else {
      request.headers.set('Authorization', `Bearer ${token}`);
    }
    return request;
  };

  return {
    onRequest({ request }) {
      const prepared = withHeaders(request, readToken());
      if (refreshAccessToken !== undefined && prepared.headers.has('Authorization')) {
        replayCopies.set(prepared, prepared.clone());
      }
      return prepared;
    },

    async onResponse({ request, response, options }) {
      const copy = replayCopies.get(request);
      replayCopies.delete(request);
      if (copy === undefined || refreshAccessToken === undefined) return undefined;
      if (!(await isTokenExpired(response))) return undefined;

      const token = await refreshAccessToken();
      if (token === null) return undefined;
      return options.fetch(withHeaders(copy, token));
    },
  };
}

/**
 * Turns every non-2xx response into a thrown error:
 * a contract-valid problem+json body → `ProblemError`, anything else →
 * `UnexpectedResponseError`. No response → `NetworkError`; client deadline →
 * `RequestTimeoutError`. Callers therefore only ever see successful `data`.
 */
export const problemMiddleware: Middleware = {
  async onResponse({ response }) {
    if (response.ok) return undefined;

    const requestId = response.headers.get('X-Request-Id');
    const contentType = response.headers.get('Content-Type');
    if (!isProblemContentType(contentType)) {
      throw new UnexpectedResponseError(
        response.status,
        requestId,
        `non-problem error response (Content-Type: ${contentType ?? 'none'})`,
      );
    }

    let body: unknown;
    try {
      body = await response.clone().json();
    } catch {
      throw new UnexpectedResponseError(response.status, requestId, 'problem body is not JSON');
    }

    const parsed = problemSchema.safeParse(body);
    if (!parsed.success) {
      throw new UnexpectedResponseError(
        response.status,
        requestId,
        `problem body does not match the contract: ${parsed.error.message}`,
      );
    }
    throw new ProblemError(parsed.data, response.status, requestId);
  },

  onError({ error }) {
    if (
      error instanceof ProblemError ||
      error instanceof UnexpectedResponseError ||
      error instanceof NetworkError ||
      error instanceof RequestTimeoutError
    ) {
      return error;
    }
    if (error instanceof Object && 'name' in error && error.name === 'TimeoutError') {
      return new RequestTimeoutError(error);
    }
    // fetch() rejects with TypeError when no HTTP response arrives. Aborts
    // (DOMException "AbortError", e.g. a cancelled query) are passed through.
    if (error instanceof TypeError) return new NetworkError(error);
    return undefined;
  },
};

export function createApiClient(options: ApiClientOptions) {
  const timeoutMs = options.timeoutMs ?? REQUEST_TIMEOUT_MS;
  const client = createClient<paths>({
    baseUrl: options.baseUrl,
    // Resolved per call so test interceptors installed later are honoured;
    // the caller's signal (e.g. a cancelled query) and the deadline both abort.
    fetch: (request) =>
      globalThis.fetch(request, {
        signal: AbortSignal.any([request.signal, AbortSignal.timeout(timeoutMs)]),
      }),
  });
  // onResponse runs in reverse order: auth replays first, then problems map.
  client.use(
    problemMiddleware,
    authMiddleware(
      options.getAccessToken,
      options.createRequestId ?? (() => crypto.randomUUID()),
      options.refreshAccessToken,
    ),
  );
  return client;
}

export type ApiClient = ReturnType<typeof createApiClient>;

/**
 * Returns the body of a successful call. Errors never reach here (the
 * middleware throws them), so a missing body is itself unexpected.
 */
export function requireData<T>(result: { readonly data?: T; readonly response: Response }): T {
  if (result.data === undefined) {
    throw new UnexpectedResponseError(
      result.response.status,
      result.response.headers.get('X-Request-Id'),
      'successful response without a body',
    );
  }
  return result.data;
}

/**
 * Single-flight refresh: concurrent callers share one `/auth/refresh` call
 * (a second call would re-use a rotated token and revoke the family).
 */
export function createRefresher(
  refresh: () => Promise<string | null>,
): () => Promise<string | null> {
  let inFlight: Promise<string | null> | null = null;
  return () => {
    inFlight ??= refresh().finally(() => {
      inFlight = null;
    });
    return inFlight;
  };
}

// The refresh call goes through the same client; it can never loop because
// its own 401 is REFRESH_TOKEN_INVALID, not TOKEN_EXPIRED.
const refreshAccessToken = createRefresher(async () => {
  try {
    const { data } = requireData(
      await api.POST('/auth/refresh', { params: { header: { 'X-Requested-With': 'fetch' } } }),
    );
    startSession(data);
    return data.access_token;
  } catch (error) {
    // A refused refresh ends the session; a network failure keeps it, but a
    // boot-time restore still has to settle.
    if (error instanceof ProblemError || getSession().status === 'restoring') endSession();
    return null;
  }
});

/** The application's only API client: same origin as the page (no CORS, ADR-0007). */
export const api: ApiClient = createApiClient({
  baseUrl: new URL(API_PATH, window.location.origin).href,
  getAccessToken,
  refreshAccessToken,
});

/** Boot: renew the session from the HttpOnly refresh cookie, if there is one. */
export async function restoreSession(): Promise<void> {
  await refreshAccessToken();
}
