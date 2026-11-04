import { MutationCache, QueryCache, QueryClient } from '@tanstack/react-query';
import { NetworkError, isAuthError } from './api/problem';

/**
 * Server state lives in TanStack Query only. Queries retry once and only when
 * no response arrived; a problem answer (4xx/5xx) is final and shown as is.
 * Mutations never retry automatically (POST is not idempotent without the
 * same Idempotency-Key — ADR-0007).
 */
export function createQueryClient(onAuthError?: () => void): QueryClient {
  const handle = (error: unknown): void => {
    if (isAuthError(error)) onAuthError?.();
  };
  return new QueryClient({
    queryCache: new QueryCache({ onError: handle }),
    mutationCache: new MutationCache({ onError: handle }),
    defaultOptions: {
      queries: {
        retry: (failureCount, error) => error instanceof NetworkError && failureCount < 1,
        refetchOnWindowFocus: false,
      },
      mutations: {
        retry: false,
      },
    },
  });
}
