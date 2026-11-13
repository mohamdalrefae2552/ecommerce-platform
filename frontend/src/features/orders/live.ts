import { z } from 'zod';
import type { OrderStatus } from './status';

/** Live events of requirement 4 (contract: POST /broadcasting/auth). */
export const ORDER_PLACED = 'order.placed';
export const ORDER_STATUS_CHANGED = 'order.status_changed';

/** Channel of one order: only its owner may subscribe. */
export const orderChannel = (orderId: string) => `orders.${orderId}`;
/** Channel of the admin dashboard: only admins may subscribe. */
export const ADMIN_ORDERS_CHANNEL = 'admin.orders';

const status = z.enum([
  'pending',
  'confirmed',
  'shipped',
  'delivered',
  'cancelled',
] as const satisfies readonly OrderStatus[]);

/** Payload of `order.status_changed`; pushed data is validated like any input. */
export const statusChangedSchema = z.object({
  order_id: z.string(),
  from: status,
  to: status,
  changed_at: z.string(),
});
