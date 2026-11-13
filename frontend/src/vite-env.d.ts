/// <reference types="vite/client" />

interface ImportMetaEnv {
  /** `1` serves the API from MSW handlers (`src/mocks/`) — dev server only. */
  readonly VITE_API_MOCKS?: string;
  /** Public key of the WebSocket application (default: `ecommerce-web`). */
  readonly VITE_REVERB_APP_KEY?: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}
