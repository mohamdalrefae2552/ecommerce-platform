import type { components } from '../../api/schema';

export type OrderStatus = components['schemas']['OrderStatus'];

export const statusLabels: Record<OrderStatus, string> = {
  pending: 'قيد الانتظار',
  confirmed: 'مؤكد',
  shipped: 'تم الشحن',
  delivered: 'تم التسليم',
  cancelled: 'ملغى',
};

export const allStatuses = Object.keys(statusLabels) as OrderStatus[];

/** Same state machine as the contract; the server enforces it again (409). */
export const allowedTransitions: Record<OrderStatus, readonly OrderStatus[]> = {
  pending: ['confirmed', 'cancelled'],
  confirmed: ['shipped', 'cancelled'],
  shipped: ['delivered'],
  delivered: [],
  cancelled: [],
};
