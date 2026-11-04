import { useMutation, useQueryClient } from '@tanstack/react-query';
import { api, requireData } from '../../api/client';
import type { components } from '../../api/schema';
import { showToast } from '../../components/toast';
import { orderKeys } from './queries';
import type { OrderStatus } from './status';

type CreateOrderRequest = components['schemas']['CreateOrderRequest'];

export function useCreateOrder() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async ({ body, key }: { body: CreateOrderRequest; key: string }) =>
      requireData(
        await api.POST('/orders', { params: { header: { 'Idempotency-Key': key } }, body }),
      ),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: orderKeys.all });
      // Stock changed: product pages and lists must be re-read.
      void queryClient.invalidateQueries({ queryKey: ['products'] });
    },
    // A refused order (e.g. 409 INSUFFICIENT_STOCK) means our stock numbers are stale.
    onError: () => queryClient.invalidateQueries({ queryKey: ['products'] }),
  });
}

export function useChangeOrderStatus(orderId: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (status: OrderStatus) =>
      requireData(
        await api.PATCH('/orders/{orderId}/status', {
          params: { path: { orderId } },
          body: { status },
        }),
      ),
    onSuccess: () => {
      showToast('تم تغيير الحالة');
    },
    // Success or failure (e.g. 409 because someone else already moved it): re-read the truth.
    onSettled: () => queryClient.invalidateQueries({ queryKey: orderKeys.all }),
  });
}
