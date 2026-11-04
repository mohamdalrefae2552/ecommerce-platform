import type { Problem } from '../api/problem';
import type { components } from '../api/schema';

type Schemas = components['schemas'];
export type User = Schemas['User'];

/**
 * In-memory backend behind the MSW handlers (tests and VITE_API_MOCKS=1).
 * It follows the contract's rules (roles, 404 for foreign orders, state
 * machine, idempotency, stock) so the UI is exercised against the same
 * answers the real API gives. Reset between tests with `resetDb()`.
 */
interface Db {
  users: Map<string, User & { password: string }>;
  /** access token → user id */
  tokens: Map<string, string>;
  expiredTokens: Set<string>;
  /** User the (simulated) HttpOnly refresh cookie belongs to. */
  refreshCookieUser: string | null;
  sequence: number;
}

export const MOCK_REQUEST_ID = '01JC3Z9A1B2C3D4E5F6G7H8J9K';
export const PASSWORD = 'correct-horse-battery';

export const customer: User = {
  id: '01JC3Z8X7Q4M2N5P6R7S8T9V0W',
  name: 'Lina Haddad',
  email: 'lina@example.com',
  role: 'customer',
  created_at: '2026-11-01T17:41:00Z',
};

export const otherCustomer: User = {
  id: '01JC3Z8X7Q4M2N5P6R7S8T9V0X',
  name: 'Omar Saleh',
  email: 'omar@example.com',
  role: 'customer',
  created_at: '2026-11-01T17:42:00Z',
};

export const admin: User = {
  id: '01JC3Z8X7Q4M2N5P6R7S8T9V0Y',
  name: 'Admin',
  email: 'admin@example.com',
  role: 'admin',
  created_at: '2026-11-01T17:40:00Z',
};

function freshDb(): Db {
  return {
    users: new Map(
      [customer, otherCustomer, admin].map((u) => [u.id, { ...u, password: PASSWORD }]),
    ),
    tokens: new Map(),
    expiredTokens: new Set(),
    refreshCookieUser: null,
    sequence: 0,
  };
}

export let db: Db = freshDb();

export function resetDb(): void {
  db = freshDb();
}

/** Crockford base32 ULID-shaped id (sortable enough for mocks). */
export function newId(): string {
  db.sequence += 1;
  const alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
  let n = db.sequence;
  let tail = '';
  for (let i = 0; i < 10; i += 1) {
    tail = (alphabet[n % 32] ?? '0') + tail;
    n = Math.floor(n / 32);
  }
  return `01JD00000000000000${tail.slice(2)}`;
}

export function issueToken(userId: string): Schemas['Token'] {
  const user = db.users.get(userId);
  if (user === undefined) throw new Error(`unknown mock user ${userId}`);
  const accessToken = `mock-access.${userId}.${String((db.sequence += 1))}`;
  db.tokens.set(accessToken, userId);
  db.refreshCookieUser = userId;
  return {
    access_token: accessToken,
    token_type: 'Bearer',
    expires_in: 900,
    user: toPublicUser(user),
  };
}

export function toPublicUser(user: User): User {
  return {
    id: user.id,
    name: user.name,
    email: user.email,
    role: user.role,
    created_at: user.created_at,
  };
}

/** A problem body exactly as the API shapes it (contracts/errors.md). */
export function problem(
  code: Problem['code'],
  status: number,
  instance: string,
  extra: Partial<Problem> = {},
): Problem {
  const kebab = code.toLowerCase().replaceAll('_', '-');
  return {
    type: `https://ecommerce.local/problems/${kebab}`,
    title: code
      .toLowerCase()
      .split('_')
      .map((w, i) => (i === 0 ? w.charAt(0).toUpperCase() + w.slice(1) : w))
      .join(' '),
    status,
    instance,
    code,
    trace_id: MOCK_REQUEST_ID,
    ...extra,
  };
}
