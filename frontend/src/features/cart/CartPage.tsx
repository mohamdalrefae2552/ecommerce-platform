import { useQueries } from '@tanstack/react-query';
import { Link, useNavigate } from '@tanstack/react-router';
import { useRef, useState } from 'react';
import { ProblemError } from '../../api/problem';
import { useSession } from '../../auth/session';
import { ErrorNotice } from '../../components/ErrorNotice';
import { showToast } from '../../components/toast';
import { formatMinor } from '../../lib/format';
import { productQuery } from '../catalog/queries';
import { useCreateOrder } from '../orders/mutations';
import {
  MAX_QUANTITY,
  applyAvailability,
  clearCart,
  removeFromCart,
  setQuantity,
  useCart,
} from './cart';

export function CartPage() {
  const lines = useCart();
  const session = useSession();
  const navigate = useNavigate();
  const createOrder = useCreateOrder();
  const [elapsedMs, setElapsedMs] = useState<number | null>(null);
  // One Idempotency-Key per distinct cart content: retrying the same cart after a
  // timeout or a 503 re-sends the same key (no duplicate order); a changed cart gets a new one.
  const attempt = useRef<{ signature: string; key: string } | null>(null);

  // Live stock per line (existing GET /products/{id}); the server stays the real check.
  const stockQueries = useQueries({ queries: lines.map((l) => productQuery(l.productId)) });
  const availableById = new Map<string, number>();
  lines.forEach((l, i) => {
    const stock = stockQueries[i]?.data?.stock;
    if (stock?.status === 'in_stock' || stock?.status === 'out_of_stock') {
      availableById.set(l.productId, stock.available ?? 0);
    }
  });
  const shortages = createOrder.error instanceof ProblemError ? createOrder.error.shortages : [];

  const productNames = new Map(lines.map((l) => [l.productId, l.name]));
  const total = lines.reduce((sum, l) => sum + l.unitPriceMinor * l.quantity, 0);
  const isAdmin = session.status === 'authenticated' && session.user.role === 'admin';

  function placeOrder() {
    const items = lines.map((l) => ({ product_id: l.productId, quantity: l.quantity }));
    const signature = JSON.stringify(items);
    if (attempt.current?.signature !== signature) {
      attempt.current = { signature, key: crypto.randomUUID() };
    }
    const startedAt = performance.now();
    createOrder.mutate(
      { body: { items }, key: attempt.current.key },
      {
        onSettled: () => {
          setElapsedMs(Math.round(performance.now() - startedAt));
        },
        onSuccess: ({ data }) => {
          attempt.current = null;
          showToast('تم إنشاء الطلب');
          clearCart();
          void navigate({ to: '/orders/$orderId', params: { orderId: data.id } });
        },
      },
    );
  }

  return (
    <section>
      <h1>السلة</h1>
      {lines.length === 0 ? (
        <p>
          السلة فارغة.{' '}
          <Link to="/products" search={{ page: 1 }}>
            تصفح المنتجات
          </Link>
        </p>
      ) : (
        <>
          <table>
            <thead>
              <tr>
                <th>المنتج</th>
                <th>سعر الوحدة</th>
                <th>الكمية</th>
                <th>المتوفر الآن</th>
                <th>الإجمالي</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {lines.map((l) => {
                const available = availableById.get(l.productId);
                const tooMany = available !== undefined && l.quantity > available;
                return (
                  <tr key={l.productId} className={tooMany ? 'line-short' : undefined}>
                    <td>{l.name}</td>
                    <td>{formatMinor(l.unitPriceMinor)}</td>
                    <td>
                      <input
                        type="number"
                        aria-label={`كمية ${l.name}`}
                        min={1}
                        max={MAX_QUANTITY}
                        value={l.quantity}
                        onChange={(e) => {
                          setQuantity(l.productId, Number(e.target.value));
                        }}
                      />
                    </td>
                    <td>
                      {available === undefined ? (
                        <span className="muted">غير معروف</span>
                      ) : (
                        <span>المتوفر الآن: {available}</span>
                      )}
                      {tooMany && (
                        <p role="alert" className="degraded">
                          الكمية المطلوبة أكبر من المتوفر
                        </p>
                      )}
                    </td>
                    <td>{formatMinor(l.unitPriceMinor * l.quantity)}</td>
                    <td>
                      <button
                        type="button"
                        onClick={() => {
                          removeFromCart(l.productId);
                        }}
                      >
                        حذف
                      </button>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
          <p>المجموع: {formatMinor(total)}</p>
          {isAdmin && <p role="alert">حساب المسؤول لا يستطيع إنشاء طلبات.</p>}
          {session.status === 'anonymous' && (
            <p>
              <Link to="/login" search={{ redirect: '/cart' }}>
                سجّل الدخول
              </Link>{' '}
              لإتمام الطلب.
            </p>
          )}
          {createOrder.error !== null && (
            <ErrorNotice error={createOrder.error} productNames={productNames} />
          )}
          {shortages.length > 0 && (
            <p>
              <button
                type="button"
                onClick={() => {
                  applyAvailability(shortages);
                  createOrder.reset();
                }}
              >
                اطلب المتوفر فقط
              </button>
            </p>
          )}
          {elapsedMs !== null && <p>زمن استجابة آخر محاولة: {elapsedMs} مللي ثانية</p>}
          <button
            type="button"
            disabled={createOrder.isPending || session.status !== 'authenticated' || isAdmin}
            onClick={placeOrder}
          >
            {createOrder.isPending ? 'جارٍ إنشاء الطلب…' : 'تأكيد الطلب'}
          </button>{' '}
          <button type="button" onClick={clearCart}>
            تفريغ السلة
          </button>
        </>
      )}
    </section>
  );
}
