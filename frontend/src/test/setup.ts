import { cleanup } from '@testing-library/react';
import { afterAll, afterEach, beforeAll } from 'vitest';
import { setAccessToken } from '../auth/token';
import { server } from '../mocks/server';

// Any request without a handler fails the test instead of hitting the network.
beforeAll(() => {
  server.listen({ onUnhandledFrame: 'error' });
});

afterEach(() => {
  cleanup();
  server.resetHandlers();
  setAccessToken(null);
});

afterAll(() => {
  server.close();
});
