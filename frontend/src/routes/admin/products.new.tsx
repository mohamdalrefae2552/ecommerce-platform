import { createFileRoute } from '@tanstack/react-router';
import { requireRole } from '../../auth/guards';
import { CreateProductPage } from '../../features/admin/CreateProductPage';

export const Route = createFileRoute('/admin/products/new')({
  beforeLoad: ({ location }) => requireRole(location, ['admin']),
  component: CreateProductPage,
});
