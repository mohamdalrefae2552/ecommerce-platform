import { QueryClient } from '@tanstack/react-query';
import { NetworkError } from './api/problem';

/**
 * Server state lives in TanStack Query only. Queries retry once and only when
 * no response arrived; a problem answer (4xx/5xx) is final and shown as is.
 * Mutations never retry automatically (POST is not idempotent without the
 * same Idempotency-Key — ADR-0007).
 */
export function createQueryClient(): QueryClient {
  return new QueryClient({
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
