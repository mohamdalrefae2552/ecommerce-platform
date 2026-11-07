import { useSyncExternalStore } from 'react';
import type { components } from '../api/schema';

export type User = components['schemas']['User'];
export type Role = components['schemas']['Role'];
type Token = components['schemas']['Token'];

/**
 * The session lives in this module's memory only — never in localStorage,
 * sessionStorage or a readable cookie (docs/04 section 2). A reload loses it
 * and it is restored from the HttpOnly refresh cookie (`restoreSession`).
 *
 * `restoring` until the first silent refresh settles, so guards never decide
 * "logged out" before knowing.
 */
type Session =
  | { readonly status: 'restoring' }
  | { readonly status: 'anonymous' }
  | { readonly status: 'authenticated'; readonly user: User; readonly accessToken: string };

let session: Session = { status: 'restoring' };
const listeners = new Set<() => void>();
let settled: Promise<void> | null = null;
let settle: (() => void) | null = null;

function emit(next: Session): void {
  session = next;
  if (next.status !== 'restoring') {
    settle?.();
    settle = null;
  }
  for (const listener of listeners) listener();
}

export function getSession(): Session {
  return session;
}

export function getAccessToken(): string | null {
  return session.status === 'authenticated' ? session.accessToken : null;
}

function subscribeSession(listener: () => void): () => void {
  listeners.add(listener);
  return () => {
    listeners.delete(listener);
  };
}

export function startSession(token: Token): void {
  emit({ status: 'authenticated', user: token.user, accessToken: token.access_token });
}

export function endSession(): void {
  emit({ status: 'anonymous' });
}

/** Back to the boot state (tests, and before the first silent refresh). */
export function resetSession(): void {
  settled = null;
  settle = null;
  emit({ status: 'restoring' });
}

/** Resolves once the session is known (authenticated or anonymous). */
export function sessionSettled(): Promise<void> {
  if (session.status !== 'restoring') return Promise.resolve();
  settled ??= new Promise<void>((resolve) => {
    settle = resolve;
  });
  return settled;
}

export function useSession(): Session {
  return useSyncExternalStore(subscribeSession, getSession, getSession);
}
