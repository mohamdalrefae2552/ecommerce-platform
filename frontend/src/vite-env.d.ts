/// <reference types="vite/client" />

interface ImportMetaEnv {
  /** `1` serves the API from MSW handlers (`src/mocks/`) — dev server only. */
  readonly VITE_API_MOCKS?: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}
