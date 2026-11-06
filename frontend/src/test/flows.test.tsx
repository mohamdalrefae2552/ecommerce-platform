import { fireEvent, screen, waitFor } from '@testing-library/react';
import { HttpResponse, http } from 'msw';
import { beforeEach, describe, expect, it } from 'vitest';
import type { components } from '../api/schema';
import { addToCart, clearCart } from '../features/cart/cart';
import { PASSWORD, admin, customer, problem } from '../mocks/db';
import { apiUrl, healthOk, json, problemResponse } from '../mocks/handlers';
import { server } from '../mocks/server';
import { product as productFixture, stockIs } from './helpers';
import { renderApp } from './renderApp';

type Order = components['schemas']['Order'];

const PRODUCT_ID = '01JC40A1B2C3D4E5F6G7H8J9KM';
const ORDER_ID = '01JC41D4E5F6G7H8J9KAMBNCPQ';

function order(status: Order['status'] = 'pending'): Order {
  const money = { amount_minor: 450000000, currency: 'SYP' } as const;
  return {
    id: ORDER_ID,
    user_id: customer.id,
    status,
    items: [
      {
        product_id: PRODUCT_ID,
        product_name: 'هاتف ذكي X200',
        unit_price: money,
        quantity: 1,
        line_total: money,
      },
    ],
    total: money,
    status_history: [
      { from: null, to: 'pending', actor_role: 'customer', note: null, at: '2026-11-04T10:00:00Z' },
    ],
    created_at: '2026-11-04T10:00:00Z',
    updated_at: '2026-11-04T10:00:00Z',
  };
}

beforeEach(() => {
  clearCart();
  // The root layout shows the health indicator on every page.
  server.use(http.get(apiUrl('/health'), () => json(healthOk)));
  // Cart lines read the live stock of their product.
  stockIs(5);
});

function fillCart() {
  addToCart({ productId: PRODUCT_ID, name: 'هاتف ذكي X200', unitPriceMinor: 450000000 });
}

describe('login', () => {
  it('logs a customer in and shows the logout control', async () => {
    renderApp('/login');

    fireEvent.change(await screen.findByLabelText('البريد الإلكتروني'), {
      target: { value: customer.email },
    });
    fireEvent.change(screen.getByLabelText('كلمة المرور'), { target: { value: PASSWORD } });
    fireEvent.click(screen.getByRole('button', { name: 'دخول' }));

    expect(await screen.findByRole('button', { name: 'تسجيل الخروج' })).toBeDefined();
  });

  it('shows the Arabic message for wrong credentials', async () => {
    renderApp('/login');

    fireEvent.change(await screen.findByLabelText('البريد الإلكتروني'), {
      target: { value: customer.email },
    });
    fireEvent.change(screen.getByLabelText('كلمة المرور'), {
      target: { value: 'wrong-password-1' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'دخول' }));

    expect(await screen.findByText('البريد الإلكتروني أو كلمة المرور غير صحيحة.')).toBeDefined();
  });

  it('shows the retry delay on 429', async () => {
    server.use(
      http.post(apiUrl('/auth/login'), () =>
        problemResponse(problem('RATE_LIMITED', 429, '/api/v1/auth/login', { retry_after: 42 })),
      ),
    );
    renderApp('/login');

    fireEvent.change(await screen.findByLabelText('البريد الإلكتروني'), {
      target: { value: customer.email },
    });
    fireEvent.change(screen.getByLabelText('كلمة المرور'), { target: { value: PASSWORD } });
    fireEvent.click(screen.getByRole('button', { name: 'دخول' }));

    expect(await screen.findByText(/حاولت كثيراً، انتظر 42 ثانية/)).toBeDefined();
  });
});

describe('placing an order', () => {
  it('creates the order with an Idempotency-Key and opens it', async () => {
    let key: string | null = null;
    server.use(
      http.post(apiUrl('/orders'), ({ request }) => {
        key = request.headers.get('Idempotency-Key');
        return json({ data: order() }, 201);
      }),
      http.get(apiUrl(`/orders/${ORDER_ID}`), () => json({ data: order() })),
    );
    fillCart();
    renderApp('/cart', { as: customer });

    fireEvent.click(await screen.findByRole('button', { name: 'تأكيد الطلب' }));

    expect(await screen.findByRole('heading', { name: `الطلب ${ORDER_ID}` })).toBeDefined();
    expect(key).toMatch(/^[0-9a-f-]{36}$/);
  });

  it('lists every short product on 409 INSUFFICIENT_STOCK', async () => {
    server.use(
      http.post(apiUrl('/orders'), () =>
        problemResponse(
          problem('INSUFFICIENT_STOCK', 409, '/api/v1/orders', {
            shortages: [{ product_id: PRODUCT_ID, requested: 3, available: 1 }],
          }),
        ),
      ),
    );
    fillCart();
    renderApp('/cart', { as: customer });

    fireEvent.click(await screen.findByRole('button', { name: 'تأكيد الطلب' }));

    expect(await screen.findByText(/هاتف ذكي X200: المطلوب 3، المتوفر/)).toBeDefined();
  });

  it('shows the inventory-down message and the measured time on 503', async () => {
    server.use(
      http.post(apiUrl('/orders'), () =>
        problemResponse(
          problem('INVENTORY_UNAVAILABLE', 503, '/api/v1/orders', { retry_after: 5 }),
        ),
      ),
    );
    fillCart();
    renderApp('/cart', { as: customer });

    fireEvent.click(await screen.findByRole('button', { name: 'تأكيد الطلب' }));

    expect(await screen.findByText(/خدمة المخزون غير متاحة حالياً/)).toBeDefined();
    expect(await screen.findByText(/مللي ثانية/)).toBeDefined();
  });
});

describe('order security', () => {
  it('shows "order not found" for another customer order (404)', async () => {
    server.use(
      http.get(apiUrl(`/orders/${ORDER_ID}`), () =>
        problemResponse(problem('NOT_FOUND', 404, `/api/v1/orders/${ORDER_ID}`)),
      ),
    );
    renderApp(`/orders/${ORDER_ID}`, { as: customer });

    expect(await screen.findByRole('heading', { name: 'الطلب غير موجود' })).toBeDefined();
  });

  it('offers a customer no way to change the status of their own order', async () => {
    server.use(http.get(apiUrl(`/orders/${ORDER_ID}`), () => json({ data: order() })));
    renderApp(`/orders/${ORDER_ID}`, { as: customer });

    expect(await screen.findByText('نسخ رقم الطلب')).toBeDefined();
    expect(screen.queryByRole('button', { name: /الحالة إلى/ })).toBeNull();
  });
});

describe('admin order management', () => {
  it('offers only allowed transitions and sends the chosen status', async () => {
    let sent: unknown = null;
    server.use(
      http.get(apiUrl('/orders'), () =>
        json({
          data: [
            {
              id: ORDER_ID,
              user_id: customer.id,
              status: 'pending',
              total: { amount_minor: 450000000, currency: 'SYP' },
              item_count: 1,
              created_at: '2026-11-04T10:00:00Z',
              updated_at: '2026-11-04T10:00:00Z',
            },
          ],
          meta: { page: 1, per_page: 20, total: 1, last_page: 1 },
          links: { first: '', prev: null, next: null, last: '' },
        }),
      ),
      http.patch(apiUrl(`/orders/${ORDER_ID}/status`), async ({ request }) => {
        sent = await request.json();
        return json({ data: order('confirmed') });
      }),
    );
    renderApp('/orders', { as: admin });

    const confirm = await screen.findByRole('button', { name: 'نقل إلى: مؤكد' });
    expect(screen.queryByRole('button', { name: 'نقل إلى: تم التسليم' })).toBeNull();
    fireEvent.click(confirm);

    await waitFor(() => {
      expect(sent).toEqual({ status: 'confirmed' });
    });
  });

  it('shows ORDER_INVALID_TRANSITION from the server', async () => {
    server.use(
      http.get(apiUrl(`/orders/${ORDER_ID}`), () => json({ data: order() })),
      http.patch(apiUrl(`/orders/${ORDER_ID}/status`), () =>
        HttpResponse.json(
          problem('ORDER_INVALID_TRANSITION', 409, `/api/v1/orders/${ORDER_ID}/status`),
          { status: 409, headers: { 'Content-Type': 'application/problem+json' } },
        ),
      ),
    );
    renderApp(`/orders/${ORDER_ID}`, { as: admin });

    fireEvent.click(await screen.findByRole('button', { name: 'نقل إلى: مؤكد' }));

    expect(
      await screen.findByText('لا يمكن نقل الطلب إلى هذه الحالة من حالته الحالية.'),
    ).toBeDefined();
  });
});

describe('admin dashboard', () => {
  it('sets the stock with a delta computed from the current quantity', async () => {
    let sent: unknown = null;
    let key: string | null = null;
    const product = {
      id: PRODUCT_ID,
      name: 'هاتف ذكي X200',
      description: 'وصف',
      price: { amount_minor: 450000000, currency: 'SYP' },
      category: { id: '01JC3Z0Y1E2C3T4R5N6C7S8A9B', slug: 'electronics', name: 'إلكترونيات' },
      image_url: 'http://localhost:8080/storage/products/x.webp',
      stock: { status: 'in_stock', available: 5 },
      created_at: '2026-11-01T17:41:00Z',
    };
    server.use(
      http.get(apiUrl('/categories'), () => json({ data: [] })),
      http.get(apiUrl('/products'), () =>
        json({
          data: [product],
          meta: { page: 1, per_page: 12, total: 1, last_page: 1 },
          links: { first: '', prev: null, next: null, last: '' },
        }),
      ),
      http.get(apiUrl('/orders'), () =>
        json({
          data: [],
          meta: { page: 1, per_page: 20, total: 0, last_page: 1 },
          links: { first: '', prev: null, next: null, last: '' },
        }),
      ),
      http.put(apiUrl(`/products/${PRODUCT_ID}/stock`), async ({ request }) => {
        sent = await request.json();
        key = request.headers.get('Idempotency-Key');
        return json({ data: { product_id: PRODUCT_ID, available: 1 } });
      }),
    );
    renderApp('/admin', { as: admin });

    fireEvent.click(await screen.findByRole('button', { name: 'تعيين' }));

    await waitFor(() => {
      expect(sent).toEqual({ delta: -4 });
    });
    expect(key).toMatch(/^[0-9a-f-]{36}$/);
    expect(await screen.findByText('تم تحديث المخزون إلى 1')).toBeDefined();
  });

  it('edits the name and the price inline and shows the edit right away', async () => {
    let sent: unknown = null;
    let current = productFixture(5);
    const page = () =>
      json({
        data: [current],
        meta: { page: 1, per_page: 12, total: 1, last_page: 1 },
        links: { first: '', prev: null, next: null, last: '' },
      });
    server.use(
      http.get(apiUrl('/categories'), () => json({ data: [] })),
      http.get(apiUrl('/products'), page),
      http.get(apiUrl('/orders'), () =>
        json({
          data: [],
          meta: { page: 1, per_page: 20, total: 0, last_page: 1 },
          links: { first: '', prev: null, next: null, last: '' },
        }),
      ),
      http.patch(apiUrl(`/products/${PRODUCT_ID}`), async ({ request }) => {
        sent = await request.json();
        current = {
          ...current,
          name: 'هاتف معدّل',
          price: { amount_minor: 399900, currency: 'SYP' },
        };
        return json({ data: current });
      }),
    );
    renderApp('/admin', { as: admin });

    fireEvent.click(await screen.findByRole('button', { name: 'تعديل' }));
    fireEvent.change(screen.getByLabelText('الاسم الجديد: هاتف ذكي X200'), {
      target: { value: '  هاتف معدّل ' },
    });
    fireEvent.change(screen.getByLabelText('السعر الجديد (ل.س): هاتف ذكي X200'), {
      target: { value: '3999' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'حفظ' }));

    await waitFor(() => {
      expect(sent).toEqual({ name: 'هاتف معدّل', price_minor: 399900 });
    });
    expect(await screen.findByText('تم حفظ تعديل «هاتف معدّل»')).toBeDefined();
    expect(await screen.findByRole('link', { name: 'هاتف معدّل' })).toBeDefined();
  });
});
