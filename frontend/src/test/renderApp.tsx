import { QueryClientProvider } from '@tanstack/react-query';
import { RouterProvider, createMemoryHistory } from '@tanstack/react-router';
import { render } from '@testing-library/react';
import { endSession, startSession } from '../auth/session';
import { type User, issueToken } from '../mocks/db';
import { createQueryClient } from '../queryClient';
import { createAppRouter } from '../router';

interface RenderAppOptions {
  /** Log in as this mock user first; anonymous when omitted. */
  readonly as?: User;
}

/** Renders the real route tree at `path` with a fresh query cache. */
export function renderApp(path: string, options: RenderAppOptions = {}) {
  if (options.as === undefined) {
    endSession();
  } else {
    startSession(issueToken(options.as.id));
  }
  const queryClient = createQueryClient();
  const router = createAppRouter(queryClient, createMemoryHistory({ initialEntries: [path] }));
  render(
    <QueryClientProvider client={queryClient}>
      <RouterProvider router={router} />
    </QueryClientProvider>,
  );
  return { router, queryClient };
}
