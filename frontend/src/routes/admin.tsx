import { createFileRoute } from '@tanstack/react-router';
import { requireRole } from '../auth/guards';
import { AdminPage } from '../features/admin/AdminPage';

export const Route = createFileRoute('/admin')({
  beforeLoad: ({ location }) => requireRole(location, ['admin']),
  component: AdminPage,
});
