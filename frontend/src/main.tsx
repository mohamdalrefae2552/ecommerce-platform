import { QueryClientProvider } from '@tanstack/react-query';
import { RouterProvider } from '@tanstack/react-router';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
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

const queryClient = createQueryClient();
const router = createAppRouter(queryClient);

void enableMocking().then(() => {
  createRoot(rootElement).render(
    <StrictMode>
      <QueryClientProvider client={queryClient}>
        <RouterProvider router={router} />
      </QueryClientProvider>
    </StrictMode>,
  );
});
