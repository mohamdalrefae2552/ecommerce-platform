import { setupWorker } from 'msw/browser';
import { handlers } from './handlers';

/** Dev-only (VITE_API_MOCKS=1). The worker script is served by vite.config.ts, never built into dist/. */
export const worker = setupWorker(...handlers);
