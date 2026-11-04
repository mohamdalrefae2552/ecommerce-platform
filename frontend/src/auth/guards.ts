import { redirect } from '@tanstack/react-router';
import { type Role, getSession, sessionSettled } from './session';

/**
 * Route guard for `beforeLoad`. UX only — the API enforces every rule again
 * (a customer forcing /admin still gets 403 from the server).
 * Logged out → /login?redirect=<here>; wrong role → home.
 */
export async function requireRole(
  location: { readonly href: string },
  roles: readonly Role[],
): Promise<void> {
  await sessionSettled();
  const session = getSession();
  if (session.status !== 'authenticated') {
    // eslint-disable-next-line @typescript-eslint/only-throw-error -- TanStack Router's redirect contract
    throw redirect({ to: '/login', search: { redirect: location.href } });
  }
  if (!roles.includes(session.user.role)) {
    // eslint-disable-next-line @typescript-eslint/only-throw-error -- TanStack Router's redirect contract
    throw redirect({ to: '/' });
  }
}

export const ANY_ROLE: readonly Role[] = ['customer', 'admin'];
