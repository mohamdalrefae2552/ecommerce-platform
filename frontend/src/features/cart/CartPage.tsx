import { Link, useNavigate } from '@tanstack/react-router';
import { useRef, useState } from 'react';
import { useSession } from '../../auth/session';
import { ErrorNotice } from '../../components/ErrorNotice';
import { showToast } from '../../components/toast';
import { formatMinor } from '../../lib/format';
import { useCreateOrder } from '../orders/mutations';
import { MAX_QUANTITY, clearCart, removeFromCart, setQuantity, useCart } from './cart';

export function CartPage() {
  const lines = useCart();
  const session = useSession();
  const navigate = useNavigate();
  const createOrder = useCreateOrder();
  const [elapsedMs, setElapsedMs] = useState<number | null>(null);
  // One Idempotency-Key per distinct cart content: retrying the same cart after a
  // timeout or a 503 re-sends the same key (no duplicate order); a changed cart gets a new one.
  const attempt = useRef<{ signature: string; key: string } | null>(null);

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
                <th>الإجمالي</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {lines.map((l) => (
                <tr key={l.productId}>
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
              ))}
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
