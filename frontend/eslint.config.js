// @ts-check
import js from '@eslint/js';
import reactHooks from 'eslint-plugin-react-hooks';
import { defineConfig, globalIgnores } from 'eslint/config';
import tseslint from 'typescript-eslint';

const storageBans = [
  { object: 'window', property: 'localStorage', message: 'Forbidden (docs/04).' },
  { object: 'globalThis', property: 'localStorage', message: 'Forbidden (docs/04).' },
];
const fetchBans = [
  { object: 'window', property: 'fetch', message: 'Call the API through src/api/client.ts.' },
  { object: 'globalThis', property: 'fetch', message: 'Call the API through src/api/client.ts.' },
];

export default defineConfig([
  globalIgnores(['dist', 'coverage', 'src/api/schema.d.ts', 'src/routeTree.gen.ts']),
  {
    files: ['**/*.{ts,tsx}'],
    extends: [
      js.configs.recommended,
      tseslint.configs.strictTypeChecked,
      tseslint.configs.stylisticTypeChecked,
      reactHooks.configs.flat.recommended,
    ],
    languageOptions: {
      parserOptions: {
        projectService: true,
        tsconfigRootDir: import.meta.dirname,
      },
    },
    rules: {
      // `any` and silencing the compiler are banned outright (react-engineer rules).
      '@typescript-eslint/no-explicit-any': 'error',
      '@typescript-eslint/ban-ts-comment': [
        'error',
        { 'ts-ignore': true, 'ts-nocheck': true, 'ts-expect-error': true },
      ],
      'no-restricted-syntax': [
        'error',
        {
          selector: "JSXAttribute[name.name='dangerouslySetInnerHTML']",
          message: 'dangerouslySetInnerHTML is forbidden (XSS; strict CSP).',
        },
      ],
      'no-restricted-globals': [
        'error',
        { name: 'fetch', message: 'Call the API through src/api/client.ts only.' },
        { name: 'localStorage', message: 'Never persist tokens or user data in localStorage.' },
      ],
      'no-restricted-properties': ['error', ...fetchBans, ...storageBans],
      '@typescript-eslint/dot-notation': ['error', { allowIndexSignaturePropertyAccess: true }],
      'no-console': 'error',
    },
  },
  {
    // The typed client is the single place that touches the Fetch API.
    files: ['src/api/client.ts'],
    rules: { 'no-restricted-properties': ['error', ...storageBans] },
  },
]);
