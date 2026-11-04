import { fireEvent, screen, waitFor } from '@testing-library/react';
import { http } from 'msw';
import { beforeEach, describe, expect, it } from 'vitest';
import { addToCart, clearCart, getCart } from '../features/cart/cart';
import { customer, problem } from '../mocks/db';
import { apiUrl, healthOk, json, problemResponse } from '../mocks/handlers';
import { server } from '../mocks/server';
import { OTHER_ID, PRODUCT_ID, stockIs } from './helpers';
import { renderApp } from './renderApp';

const NAME = 'هاتف ذكي X200';
const PICKER = `الكمية المطلوبة من ${NAME}`;
const ADD = 'أضف إلى السلة';

function line(productId = PRODUCT_ID, name = NAME) {
  return { productId, name, unitPriceMinor: 450000000 };
}

beforeEach(() => {
  clearCart();
  server.use(http.get(apiUrl('/health'), () => json(healthOk)));
});

describe('quantity picker', () => {
  it('adds N units at once and caps the picker at the known stock', async () => {
    stockIs(5);
    renderApp(`/products/${PRODUCT_ID}`, { as: customer });

    const input = await screen.findByLabelText<HTMLInputElement>(PICKER, {}, { timeout: 5000 });
    fireEvent.change(input, { target: { value: '50' } });
    expect(input.value).toBe('5');
    fireEvent.click(screen.getByRole('button', { name: `إنقاص ${PICKER}` }));
    fireEvent.click(screen.getByRole('button', { name: ADD }));

    expect(await screen.findByText('تمت إضافة 4 إلى السلة')).toBeDefined();
    expect(getCart()[0]?.quantity).toBe(4);
  });

  it('caps the cart total of one product at 100', async () => {
    stockIs(null);
    addToCart(line(), 95);
    renderApp(`/products/${PRODUCT_ID}`, { as: customer });

    const input = await screen.findByLabelText<HTMLInputElement>(PICKER, {}, { timeout: 5000 });
    fireEvent.change(input, { target: { value: '50' } });
    expect(input.value).toBe('5');
    fireEvent.click(screen.getByRole('button', { name: ADD }));

    expect(getCart()[0]?.quantity).toBe(100);
    await waitFor(() => {
      expect(screen.getByRole<HTMLButtonElement>('button', { name: ADD }).disabled).toBe(true);
    });
  });

  it('disables add to cart when the product is out of stock', async () => {
    stockIs(0);
    renderApp(`/products/${PRODUCT_ID}`, { as: customer });

    const add = await screen.findByRole<HTMLButtonElement>('button', { name: ADD });
    expect(add.disabled).toBe(true);
  });
});

describe('cart live stock', () => {
  it('shows the current stock and warns without blocking the submit', async () => {
    stockIs(2);
    addToCart(line(), 3);
    renderApp('/cart', { as: customer });

    expect(await screen.findByText('المتوفر الآن: 2')).toBeDefined();
    expect(screen.getByText('الكمية المطلوبة أكبر من المتوفر')).toBeDefined();
    expect(screen.getByRole<HTMLButtonElement>('button', { name: 'تأكيد الطلب' }).disabled).toBe(
      false,
    );
  });

  it('shows no warning when the stock covers the request', async () => {
    stockIs(5);
    addToCart(line(), 3);
    renderApp('/cart', { as: customer });

    expect(await screen.findByText('المتوفر الآن: 5')).toBeDefined();
    expect(screen.queryByText('الكمية المطلوبة أكبر من المتوفر')).toBeNull();
  });
});

describe('order what is available', () => {
  function shortOn(available: number, productId = PRODUCT_ID) {
    let posts = 0;
    server.use(
      http.post(apiUrl('/orders'), () => {
        posts += 1;
        return problemResponse(
          problem('INSUFFICIENT_STOCK', 409, '/api/v1/orders', {
            shortages: [{ product_id: productId, requested: 3, available }],
          }),
        );
      }),
    );
    return () => posts;
  }

  it('lowers the short line to what is available and does not auto-submit', async () => {
    stockIs(1);
    stockIs(9, OTHER_ID, 'سماعة');
    const posts = shortOn(1);
    addToCart(line(), 3);
    addToCart(line(OTHER_ID, 'سماعة'), 2);
    renderApp('/cart', { as: customer });

    fireEvent.click(await screen.findByRole('button', { name: 'تأكيد الطلب' }));
    fireEvent.click(await screen.findByRole('button', { name: 'اطلب المتوفر فقط' }));

    await waitFor(() => {
      expect(getCart().map((l) => [l.productId, l.quantity])).toEqual([
        [PRODUCT_ID, 1],
        [OTHER_ID, 2],
      ]);
    });
    expect(posts()).toBe(1);
    expect(screen.queryByRole('button', { name: 'اطلب المتوفر فقط' })).toBeNull();
  });

  it('removes a line with nothing available', async () => {
    stockIs(0);
    shortOn(0);
    addToCart(line(), 3);
    renderApp('/cart', { as: customer });

    fireEvent.click(await screen.findByRole('button', { name: 'تأكيد الطلب' }));
    fireEvent.click(await screen.findByRole('button', { name: 'اطلب المتوفر فقط' }));

    await waitFor(() => {
      expect(getCart()).toHaveLength(0);
    });
    expect(await screen.findByText(/السلة فارغة/)).toBeDefined();
  });
});
