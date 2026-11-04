import { http } from 'msw';
import type { components } from '../api/schema';
import { apiUrl, json } from '../mocks/handlers';
import { server } from '../mocks/server';

type Product = components['schemas']['Product'];

export const PRODUCT_ID = '01JC40A1B2C3D4E5F6G7H8J9KM';
export const OTHER_ID = '01JC40A1B2C3D4E5F6G7H8J9KN';

export function product(
  available: number | null = 5,
  id = PRODUCT_ID,
  name = 'هاتف ذكي X200',
): Product {
  return {
    id,
    name,
    description: 'شاشة 6.5 إنش',
    price: { amount_minor: 450000000, currency: 'SYP' },
    category: { id: '01JC40A1B2C3D4E5F6G7H8J9KA', name: 'هواتف', slug: 'phones' },
    image_url: 'http://localhost:8080/storage/products/x.webp',
    stock:
      available === null
        ? { status: 'unknown', available: null }
        : { status: available === 0 ? 'out_of_stock' : 'in_stock', available },
    created_at: '2026-11-04T10:00:00Z',
  };
}

/** Serves GET /products/{id} with the given stock (null = inventory unknown). */
export function stockIs(available: number | null, id = PRODUCT_ID, name = 'هاتف ذكي X200') {
  server.use(
    http.get(apiUrl(`/products/${id}`), () => json({ data: product(available, id, name) })),
  );
}
