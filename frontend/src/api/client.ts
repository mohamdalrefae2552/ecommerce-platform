import createClient, { type Middleware } from 'openapi-fetch';
import { getAccessToken } from '../auth/token';
import {
  NetworkError,
  ProblemError,
  UnexpectedResponseError,
  isProblemContentType,
  problemSchema,
} from './problem';
import type { paths } from './schema';

export const API_PATH = '/api/v1';

export interface ApiClientOptions {
  /** Absolute base URL, e.g. `https://shop.example/api/v1`. */
  readonly baseUrl: string;
  readonly getAccessToken: () => string | null;
  /** Correlation id sent as `X-Request-Id` (edge accepts `[A-Za-z0-9._-]{1,128}`). */
  readonly createRequestId?: () => string;
}

/** Adds `Authorization: Bearer <token>` (when logged in) and a fresh `X-Request-Id`. */
export function requestHeadersMiddleware(
  readToken: () => string | null,
  createRequestId: () => string,
): Middleware {
  return {
    onRequest({ request }) {
      request.headers.set('X-Request-Id', createRequestId());
      const token = readToken();
      if (token === null) {
        request.headers.delete('Authorization');
      } else {
        request.headers.set('Authorization', `Bearer ${token}`);
      }
      return request;
    },
  };
}

/**
 * Turns every non-2xx response into a thrown error:
 * a contract-valid problem+json body → `ProblemError`, anything else →
 * `UnexpectedResponseError`. Fetch failures (no response) → `NetworkError`.
 * Callers therefore only ever see successful `data`.
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
      error instanceof NetworkError
    ) {
      return error;
    }
    // fetch() rejects with TypeError when no HTTP response arrives. Aborts
    // (DOMException "AbortError", e.g. a cancelled query) are passed through.
    if (error instanceof TypeError) return new NetworkError(error);
    return undefined;
  },
};

export function createApiClient(options: ApiClientOptions) {
  const client = createClient<paths>({
    baseUrl: options.baseUrl,
    // Resolve fetch per call so test interceptors installed later are honoured.
    fetch: (request) => globalThis.fetch(request),
  });
  client.use(
    requestHeadersMiddleware(
      options.getAccessToken,
      options.createRequestId ?? (() => crypto.randomUUID()),
    ),
    problemMiddleware,
  );
  return client;
}

export type ApiClient = ReturnType<typeof createApiClient>;

/** The application's only API client: same origin as the page (no CORS, ADR-0007). */
export const api: ApiClient = createApiClient({
  baseUrl: new URL(API_PATH, window.location.origin).href,
  getAccessToken,
});

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
