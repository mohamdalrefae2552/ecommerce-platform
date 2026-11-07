import { useQueries } from '@tanstack/react-query';
import { Link, useNavigate } from '@tanstack/react-router';
import { useRef, useState } from 'react';
import { ProblemError } from '../../api/problem';
import { useSession } from '../../auth/session';
import { Badge } from '../../components/Badge';
import { Button, buttonClass } from '../../components/Button';
import { EmptyState } from '../../components/EmptyState';
import { ErrorNotice } from '../../components/ErrorNotice';
import { CartIcon, ImageIcon, TrashIcon } from '../../components/icons';
import { Notice } from '../../components/Notice';
import { PageHeader } from '../../components/PageHeader';
import { QuantityPicker } from '../../components/QuantityPicker';
import { showToast } from '../../components/toast';
import { cx } from '../../lib/cx';
import { formatMinor, imagePath } from '../../lib/format';
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
  const units = lines.reduce((sum, l) => sum + l.quantity, 0);
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

  if (lines.length === 0) {
    return (
      <section>
        <PageHeader title="السلة" />
        <EmptyState icon={<CartIcon />} title="السلة فارغة.">
          <p className="empty-text">أضف منتجات من الكتالوج لتظهر هنا.</p>
          <Link to="/products" search={{ page: 1 }} className={buttonClass('primary')}>
            تصفح المنتجات
          </Link>
        </EmptyState>
      </section>
    );
  }

  return (
    <section>
      <PageHeader
        title="السلة"
        subtitle={`${String(units)} قطعة في ${String(lines.length)} منتج`}
      />
      <div className="cart-layout">
        <ul className="cart-lines" aria-label="منتجات السلة">
          {lines.map((l, i) => {
            const available = availableById.get(l.productId);
            const tooMany = available !== undefined && l.quantity > available;
            const image = stockQueries[i]?.data?.image_url;
            return (
              <li key={l.productId} className={cx('card cart-line', tooMany && 'is-short')}>
                <div className="cart-thumb">
                  {image === undefined ? <ImageIcon /> : <img src={imagePath(image)} alt="" />}
                </div>
                <div className="cart-line-info">
                  <Link
                    to="/products/$productId"
                    params={{ productId: l.productId }}
                    className="cart-line-name"
                  >
                    {l.name}
                  </Link>
                  <p className="muted small">سعر الوحدة: {formatMinor(l.unitPriceMinor)}</p>
                  {available === undefined ? (
                    <Badge dot>المتوفر الآن: غير معروف</Badge>
                  ) : (
                    <Badge dot tone={tooMany ? 'warning' : 'success'}>
                      المتوفر الآن: {available}
                    </Badge>
                  )}
                  {tooMany && (
                    <Notice role="alert" tone="warning" className="notice-sm">
                      الكمية المطلوبة أكبر من المتوفر
                    </Notice>
                  )}
                </div>
                <div className="cart-line-controls">
                  <QuantityPicker
                    label={`كمية ${l.name}`}
                    value={l.quantity}
                    max={MAX_QUANTITY}
                    onChange={(quantity) => {
                      setQuantity(l.productId, quantity);
                    }}
                  />
                  <p className="cart-line-total">{formatMinor(l.unitPriceMinor * l.quantity)}</p>
                  <Button
                    variant="ghost"
                    size="sm"
                    className="btn-danger-text"
                    icon={<TrashIcon />}
                    onClick={() => {
                      removeFromCart(l.productId);
                    }}
                  >
                    حذف
                  </Button>
                </div>
              </li>
            );
          })}
        </ul>

        <aside className="card order-summary" aria-label="ملخص الطلب">
          <h2 className="card-title">ملخص الطلب</h2>
          <dl className="summary-rows">
            <div>
              <dt>عدد القطع</dt>
              <dd>{units}</dd>
            </div>
            <div className="summary-total">
              <dt>المجموع</dt>
              <dd>{formatMinor(total)}</dd>
            </div>
          </dl>
          {isAdmin && (
            <Notice role="alert" tone="danger">
              حساب المسؤول لا يستطيع إنشاء طلبات.
            </Notice>
          )}
          {session.status === 'anonymous' && (
            <Notice tone="info">
              <p>
                <Link to="/login" search={{ redirect: '/cart' }}>
                  سجّل الدخول
                </Link>{' '}
                لإتمام الطلب.
              </p>
            </Notice>
          )}
          {createOrder.error !== null && (
            <ErrorNotice error={createOrder.error} productNames={productNames} />
          )}
          {shortages.length > 0 && (
            <Button
              variant="secondary"
              block
              onClick={() => {
                applyAvailability(shortages);
                createOrder.reset();
              }}
            >
              اطلب المتوفر فقط
            </Button>
          )}
          <Button
            size="lg"
            block
            disabled={createOrder.isPending || session.status !== 'authenticated' || isAdmin}
            onClick={placeOrder}
          >
            {createOrder.isPending ? 'جارٍ إنشاء الطلب…' : 'تأكيد الطلب'}
          </Button>
          <Button variant="ghost" block onClick={clearCart}>
            تفريغ السلة
          </Button>
          {elapsedMs !== null && (
            <p className="muted small summary-timing">
              زمن استجابة آخر محاولة: {elapsedMs} مللي ثانية
            </p>
          )}
        </aside>
      </div>
    </section>
  );
}
