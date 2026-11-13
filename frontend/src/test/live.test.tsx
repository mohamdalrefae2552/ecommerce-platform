import { screen, waitFor } from '@testing-library/react';
import { http } from 'msw';
import { beforeEach, describe, expect, it } from 'vitest';
import type { components } from '../api/schema';
import { admin, customer } from '../mocks/db';
import { apiUrl, healthOk, json } from '../mocks/handlers';
import { server } from '../mocks/server';
import { emitLive, liveChannels } from './fakeSocket';
import { renderApp } from './renderApp';

/*
 * Requirement 4: the order page and the admin dashboard change without a
 * page refresh when the server pushes an event (fakeSocket stands in for the
 * WebSocket; the REST answers come from MSW as usual).
 */

type Order = components['schemas']['Order'];
type OrderSummary = components['schemas']['OrderSummary'];

const ORDER_ID = '01JC41D4E5F6G7H8J9KAMBNCPQ';
const NEW_ORDER_ID = '01JC41D4E5F6G7H8J9KAMBNCPR';
const money = { amount_minor: 450000000, currency: 'SYP' } as const;
// The first render of a route also loads its code-split chunk.
const FIRST_RENDER = { timeout: 5000 };

function order(status: Order['status']): Order {
  return {
    id: ORDER_ID,
    user_id: customer.id,
    status,
    items: [
      {
        product_id: '01JC40A1B2C3D4E5F6G7H8J9KM',
        product_name: 'هاتف ذكي X200',
        unit_price: money,
        quantity: 1,
        line_total: money,
      },
    ],
    total: money,
    status_history: [
      { from: null, to: 'pending', actor_role: 'customer', note: null, at: '2026-11-13T10:00:00Z' },
    ],
    created_at: '2026-11-13T10:00:00Z',
    updated_at: '2026-11-13T10:00:00Z',
  };
}

function summary(id: string): OrderSummary {
  return {
    id,
    user_id: customer.id,
    status: 'pending',
    total: money,
    item_count: 1,
    created_at: '2026-11-13T10:00:00Z',
    updated_at: '2026-11-13T10:00:00Z',
  };
}

function orderList(orders: OrderSummary[]) {
  return json({
    data: orders,
    meta: { page: 1, per_page: 20, total: orders.length, last_page: 1 },
    links: { first: '', prev: null, next: null, last: '' },
  });
}

beforeEach(() => {
  server.use(http.get(apiUrl('/health'), () => json(healthOk)));
});

describe('order page (customer)', () => {
  it('shows the new status as soon as the server pushes it, without a refresh', async () => {
    let current = order('pending');
    server.use(http.get(apiUrl(`/orders/${ORDER_ID}`), () => json({ data: current })));
    renderApp(`/orders/${ORDER_ID}`, { as: customer });

    expect(await screen.findByText('تحديث مباشر', {}, FIRST_RENDER)).toBeDefined();
    expect(liveChannels()).toEqual([`orders.${ORDER_ID}`]);
    expect(screen.queryByText('مؤكد')).toBeNull();

    // The admin approves the order; the server pushes the event.
    current = order('confirmed');
    emitLive(`orders.${ORDER_ID}`, 'order.status_changed', {
      order_id: ORDER_ID,
      from: 'pending',
      to: 'confirmed',
      changed_at: '2026-11-13T10:05:00Z',
    });

    expect(await screen.findByText('تغيّرت حالة طلبك إلى: مؤكد')).toBeDefined();
    await waitFor(() => {
      expect(screen.getAllByText('مؤكد').length).toBeGreaterThan(0);
    });
  });

  it('re-reads the order even when the pushed data is not what the contract says', async () => {
    let current = order('pending');
    server.use(http.get(apiUrl(`/orders/${ORDER_ID}`), () => json({ data: current })));
    renderApp(`/orders/${ORDER_ID}`, { as: customer });
    await screen.findByText('تحديث مباشر', {}, FIRST_RENDER);

    current = order('shipped');
    emitLive(`orders.${ORDER_ID}`, 'order.status_changed', { to: 'teleported' });

    await waitFor(() => {
      expect(screen.getAllByText('تم الشحن').length).toBeGreaterThan(0);
    });
    // Unknown data is never shown to the user.
    expect(screen.queryByText(/teleported/)).toBeNull();
  });
});

describe('order page (admin)', () => {
  it('does not subscribe to the customer channel of the order', async () => {
    server.use(http.get(apiUrl(`/orders/${ORDER_ID}`), () => json({ data: order('pending') })));
    renderApp(`/orders/${ORDER_ID}`, { as: admin });

    expect(
      await screen.findByRole('heading', { name: `الطلب ${ORDER_ID}` }, FIRST_RENDER),
    ).toBeDefined();
    expect(liveChannels()).toEqual([]);
    expect(screen.queryByText('تحديث مباشر')).toBeNull();
  });
});

describe('admin dashboard', () => {
  it('shows a new order as soon as the server pushes it, without a refresh', async () => {
    let orders: OrderSummary[] = [];
    server.use(
      http.get(apiUrl('/categories'), () => json({ data: [] })),
      http.get(apiUrl('/products'), () =>
        json({
          data: [],
          meta: { page: 1, per_page: 12, total: 0, last_page: 1 },
          links: { first: '', prev: null, next: null, last: '' },
        }),
      ),
      http.get(apiUrl('/orders'), () => orderList(orders)),
    );
    renderApp('/admin', { as: admin });

    expect(await screen.findByText('لا توجد طلبات.', {}, FIRST_RENDER)).toBeDefined();
    expect(liveChannels()).toEqual(['admin.orders']);

    // A customer places an order; the server pushes the event.
    orders = [summary(NEW_ORDER_ID)];
    emitLive('admin.orders', 'order.placed', summary(NEW_ORDER_ID));

    expect(await screen.findByText('وصل طلب جديد')).toBeDefined();
    expect(await screen.findByRole('link', { name: NEW_ORDER_ID })).toBeDefined();
  });
});
