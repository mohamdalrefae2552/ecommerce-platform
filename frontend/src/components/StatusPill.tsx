import { type OrderStatus, statusLabels } from '../features/orders/status';

/** Order status as a coloured pill (the visible text is still the Arabic label). */
export function StatusPill({ status }: { readonly status: OrderStatus }) {
  return <span className={`pill pill-${status}`}>{statusLabels[status]}</span>;
}
