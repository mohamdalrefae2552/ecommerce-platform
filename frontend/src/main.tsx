import { QueryClientProvider } from '@tanstack/react-query';
import { RouterProvider } from '@tanstack/react-router';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import './styles.css';
import { restoreSession } from './api/client';
import { endSession } from './auth/session';
import { createQueryClient } from './queryClient';
import { createAppRouter } from './router';

async function enableMocking(): Promise<void> {
  // Dev server only: the whole branch (and MSW) is removed from production builds.
  if (import.meta.env.DEV && import.meta.env.VITE_API_MOCKS === '1') {
    const { worker } = await import('./mocks/browser');
    await worker.start({ onUnhandledFrame: 'warn' });
  }
}

const rootElement = document.getElementById('root');
if (rootElement === null) throw new Error('#root element is missing from index.html');

// A 401 that survived the silent refresh means the session is over: go to the login page.
const queryClient = createQueryClient(() => {
  endSession();
  const here = router.state.location;
  if (here.pathname !== '/login') {
    void router.navigate({ to: '/login', search: { redirect: here.href } });
  }
});
const router = createAppRouter(queryClient);

void enableMocking().then(() => {
  // Silent refresh from the HttpOnly cookie; guards wait for it to settle.
  void restoreSession();
  createRoot(rootElement).render(
    <StrictMode>
      <QueryClientProvider client={queryClient}>
        <RouterProvider router={router} />
      </QueryClientProvider>
    </StrictMode>,
  );
});
