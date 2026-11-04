import { useMutation } from '@tanstack/react-query';
import { api, requireData } from '../../api/client';
import type { components } from '../../api/schema';

type AdjustStockRequest = components['schemas']['AdjustStockRequest'];

export function useAdjustStock(productId: string) {
  return useMutation({
    mutationFn: async ({ body, key }: { body: AdjustStockRequest; key: string }) =>
      requireData(
        await api.PUT('/products/{productId}/stock', {
          params: { path: { productId }, header: { 'Idempotency-Key': key } },
          body,
        }),
      ),
  });
}

export interface NewProduct {
  readonly name: string;
  readonly description: string;
  readonly priceMinor: number;
  readonly categoryId: string;
  readonly initialStock: number;
  readonly image: File;
}

export function useCreateProduct() {
  return useMutation({
    mutationFn: async (product: NewProduct) =>
      requireData(
        await api.POST('/products', {
          // The generated type models the file as a string; the serializer sends the real File.
          body: {
            name: product.name,
            price_minor: product.priceMinor,
            category_id: product.categoryId,
            initial_stock: product.initialStock,
            image: product.image.name,
          },
          bodySerializer: () => {
            const form = new FormData();
            form.set('name', product.name);
            if (product.description !== '') form.set('description', product.description);
            form.set('price_minor', String(product.priceMinor));
            form.set('category_id', product.categoryId);
            form.set('initial_stock', String(product.initialStock));
            form.set('image', product.image);
            return form;
          },
        }),
      ),
  });
}
