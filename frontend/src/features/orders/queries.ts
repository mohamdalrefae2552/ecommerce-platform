import { queryOptions } from '@tanstack/react-query';
import { api, requireData } from '../../api/client';
import type { OrderStatus } from './status';

export const orderKeys = {
  all: ['orders'] as const,
  list: (page: number, status: OrderStatus | undefined) =>
    ['orders', 'list', page, status ?? ''] as const,
  detail: (id: string) => ['orders', 'detail', id] as const,
};

export function ordersQuery(page: number, status: OrderStatus | undefined) {
  return queryOptions({
    queryKey: orderKeys.list(page, status),
    queryFn: async ({ signal }) =>
      requireData(
        await api.GET('/orders', {
          params: { query: { page, per_page: 20, ...(status === undefined ? {} : { status }) } },
          signal,
        }),
      ),
  });
}

export function orderQuery(orderId: string) {
  return queryOptions({
    queryKey: orderKeys.detail(orderId),
    queryFn: async ({ signal }) =>
      requireData(await api.GET('/orders/{orderId}', { params: { path: { orderId } }, signal }))
        .data,
  });
}
