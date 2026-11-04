import { createFileRoute } from '@tanstack/react-router';
import { z } from 'zod';
import { ANY_ROLE, requireRole } from '../../auth/guards';
import { OrdersPage } from '../../features/orders/OrdersPage';
import { allStatuses } from '../../features/orders/status';

const searchSchema = z.object({
  page: z.number().int().min(1).catch(1),
  status: z.enum(allStatuses).optional().catch(undefined),
});

export const Route = createFileRoute('/orders/')({
  beforeLoad: ({ location }) => requireRole(location, ANY_ROLE),
  validateSearch: searchSchema,
  component: OrdersRoute,
});

function OrdersRoute() {
  const { page, status } = Route.useSearch();
  return <OrdersPage page={page} status={status} />;
}
