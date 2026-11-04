import { queryOptions } from '@tanstack/react-query';
import { api, requireData } from '../../api/client';

export const catalogKeys = {
  categories: ['categories'] as const,
  products: (category: string | undefined, page: number) =>
    ['products', 'list', category ?? '', page] as const,
  product: (id: string) => ['products', 'detail', id] as const,
};

export const PRODUCTS_PER_PAGE = 12;

export const categoriesQuery = queryOptions({
  queryKey: catalogKeys.categories,
  queryFn: async ({ signal }) => requireData(await api.GET('/categories', { signal })).data,
});

export function productsQuery(category: string | undefined, page: number) {
  return queryOptions({
    queryKey: catalogKeys.products(category, page),
    queryFn: async ({ signal }) =>
      requireData(
        await api.GET('/products', {
          params: {
            query: {
              page,
              per_page: PRODUCTS_PER_PAGE,
              ...(category === undefined ? {} : { category }),
            },
          },
          signal,
        }),
      ),
  });
}

export function productQuery(productId: string) {
  return queryOptions({
    queryKey: catalogKeys.product(productId),
    queryFn: async ({ signal }) =>
      requireData(
        await api.GET('/products/{productId}', { params: { path: { productId } }, signal }),
      ).data,
  });
}
