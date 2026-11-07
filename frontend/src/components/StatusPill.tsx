import { type OrderStatus, statusLabels } from '../features/orders/status';
import { Badge, type Tone } from './Badge';

export const statusTone: Record<OrderStatus, Tone> = {
  pending: 'warning',
  confirmed: 'info',
  shipped: 'violet',
  delivered: 'success',
  cancelled: 'danger',
};

/** Order status as a coloured pill (the visible text is still the Arabic label). */
export function StatusPill({ status }: { readonly status: OrderStatus }) {
  return (
    <Badge tone={statusTone[status]} dot>
      {statusLabels[status]}
    </Badge>
  );
}
