/// <reference types="vitest/config" />
import { readFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { tanstackRouter } from '@tanstack/router-plugin/vite';
import react from '@vitejs/plugin-react';
import { type Plugin, defineConfig, loadEnv } from 'vite';

const require = createRequire(import.meta.url);

/**
 * Serves MSW's worker script from node_modules on the dev server only, so it
 * always matches the installed msw version and can never end up in dist/
 * (there is no public/ directory).
 */
function mswWorkerOnDevServer(): Plugin {
  const workerPath = require.resolve('msw/mockServiceWorker.js');
  return {
    name: 'msw-worker-on-dev-server',
    apply: 'serve',
    configureServer(server) {
      server.middlewares.use('/mockServiceWorker.js', (_req, res, next) => {
        readFile(workerPath).then((script) => {
          res.setHeader('Content-Type', 'text/javascript; charset=utf-8');
          res.end(script);
        }, next);
      });
    },
  };
}

export default defineConfig(({ mode }) => {
  // Empty prefix: also read non-VITE_ variables (compose sets VITE_PROXY_TARGET).
  const env = loadEnv(mode, process.cwd(), '');
  const proxyTarget = env.VITE_PROXY_TARGET;

  return {
    plugins: [
      // Must run before the React plugin (generates src/routeTree.gen.ts).
      tanstackRouter({ target: 'react', autoCodeSplitting: true }),
      react(),
      mswWorkerOnDevServer(),
    ],
    server: {
      // Same origin in development too: /api goes to edge, never CORS.
      ...(proxyTarget ? { proxy: { '/api': { target: proxyTarget } } } : {}),
    },
    build: {
      sourcemap: false,
    },
    test: {
      environment: 'jsdom',
      setupFiles: ['./src/test/setup.ts'],
      include: ['src/**/*.test.{ts,tsx}'],
      restoreMocks: true,
      coverage: {
        provider: 'v8',
        include: ['src/**/*.{ts,tsx}'],
        exclude: ['src/**/*.test.{ts,tsx}', 'src/api/schema.d.ts', 'src/routeTree.gen.ts'],
      },
    },
  };
});
