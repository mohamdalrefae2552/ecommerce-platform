import { createFileRoute } from '@tanstack/react-router';
import { ANY_ROLE, requireRole } from '../../auth/guards';
import { OrderDetailPage } from '../../features/orders/OrderDetailPage';

export const Route = createFileRoute('/orders/$orderId')({
  beforeLoad: ({ location }) => requireRole(location, ANY_ROLE),
  component: OrderRoute,
});

function OrderRoute() {
  const { orderId } = Route.useParams();
  return <OrderDetailPage orderId={orderId} />;
}
