import { createFileRoute } from '@tanstack/react-router';
import { ANY_ROLE, requireRole } from '../auth/guards';
import { AccountPage } from '../features/auth/AccountPage';

export const Route = createFileRoute('/account')({
  beforeLoad: ({ location }) => requireRole(location, ANY_ROLE),
  component: AccountPage,
});
