import { cleanup } from '@testing-library/react';
import { afterAll, afterEach, beforeAll, beforeEach, vi } from 'vitest';
import { resetSession } from '../auth/session';
import { resetDb } from '../mocks/db';
import { server } from '../mocks/server';
import { disconnectRealtime } from './fakeSocket';

// Live updates: no WebSocket in tests; `emitLive` (fakeSocket) pushes events.
vi.mock('../realtime/socket', () => import('./fakeSocket'));

// Any request without a handler fails the test instead of hitting the network.
beforeAll(() => {
  server.listen({ onUnhandledFrame: 'error' });
});

beforeEach(() => {
  resetDb();
  resetSession();
  disconnectRealtime();
});

afterEach(() => {
  cleanup();
  server.resetHandlers();
});

afterAll(() => {
  server.close();
});
