/**
 * Black-box helpers over the public REST contract (contracts/openapi/openapi.yaml).
 * The types below mirror the contract schemas the harness relies on; they are
 * written by hand because the e2e container only mounts `tests/`.
 */
import { randomBytes } from 'node:crypto';
import type { APIRequestContext, APIResponse } from '@playwright/test';
import { API_PREFIX } from './env';

/** components.schemas.Role */
export type Role = 'customer' | 'admin';

/** components.schemas.User */
export interface User {
  id: string;
  name: string;
  email: string;
  role: Role;
  created_at: string;
}

/** components.schemas.Token */
export interface Token {
  access_token: string;
  token_type: 'Bearer';
  expires_in: number;
  user: User;
}

/** components.schemas.Health */
export interface Health {
  status: 'ok';
  checks: { db: 'ok'; inventory: 'ok' | 'degraded' };
}

/** components.schemas.Problem (RFC 9457 + `code`, `trace_id`). */
export interface Problem {
  type: string;
  title: string;
  status: number;
  detail?: string;
  instance?: string;
  code: string;
  trace_id: string;
  errors?: Record<string, string[]>;
  retry_after?: number;
}

/** A user created by the harness, with the credentials it was created with. */
export interface TestUser extends User {
  password: string;
  accessToken: string;
}

export const api = (path: `/${string}`): string => `${API_PREFIX}${path}`;

export function bearer(token: string): Record<string, string> {
  return { Authorization: `Bearer ${token}` };
}

/** Error carrying the problem document of an unexpected API answer. */
export class ApiError extends Error {
  constructor(
    readonly operation: string,
    readonly status: number,
    readonly problem: Problem | undefined,
    readonly rawBody: string,
  ) {
    const reason = problem ? `${problem.code}: ${problem.detail ?? problem.title} (trace_id ${problem.trace_id})` : rawBody;
    super(`${operation} -> HTTP ${status} ${reason}`);
    this.name = 'ApiError';
  }
}

export async function readProblem(response: APIResponse): Promise<Problem | undefined> {
  const contentType = response.headers()['content-type'] ?? '';
  if (!contentType.startsWith('application/problem+json')) return undefined;
  return (await response.json()) as Problem;
}

async function expectStatus(operation: string, response: APIResponse, expected: number): Promise<void> {
  if (response.status() === expected) return;
  const rawBody = await response.text();
  let problem: Problem | undefined;
  try {
    problem = await readProblem(response);
  } catch {
    problem = undefined;
  }
  throw new ApiError(operation, response.status(), problem, rawBody);
}

/** Unique, contract-valid registration data (password 12..128 chars). */
export function newCredentials(label = 'customer'): { name: string; email: string; password: string } {
  const suffix = `${Date.now().toString(36)}${randomBytes(4).toString('hex')}`;
  return {
    name: `E2E ${label} ${suffix}`,
    email: `e2e-${label}-${suffix}@example.test`,
    password: `pw-${randomBytes(12).toString('base64url')}`,
  };
}

/** POST /auth/register — creates a `customer` (the role can never be chosen). */
export async function registerCustomer(
  request: APIRequestContext,
  credentials = newCredentials(),
): Promise<User & { password: string }> {
  const response = await request.post(api('/auth/register'), {
    data: { ...credentials, password_confirmation: credentials.password },
  });
  if (response.status() === 429) {
    throw new ApiError(
      'registerUser',
      429,
      await readProblem(response),
      'register is limited to 5 per hour per IP (docs/04 section 3); every e2e request comes from the same IP',
    );
  }
  await expectStatus('registerUser', response, 201);
  const { data } = (await response.json()) as { data: User };
  return { ...data, password: credentials.password };
}

/** POST /auth/login — returns the access token (the refresh cookie stays in `request`). */
export async function login(request: APIRequestContext, email: string, password: string): Promise<Token> {
  const response = await request.post(api('/auth/login'), { data: { email, password } });
  await expectStatus('loginUser', response, 200);
  const { data } = (await response.json()) as { data: Token };
  return data;
}

/** Registers a new customer and logs it in. */
export async function createCustomer(request: APIRequestContext, label = 'customer'): Promise<TestUser> {
  const user = await registerCustomer(request, newCredentials(label));
  const token = await login(request, user.email, user.password);
  return { ...user, accessToken: token.access_token };
}
