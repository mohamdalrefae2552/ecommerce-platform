import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { useRef, useState } from 'react';
import type { components } from '../../api/schema';
import { ErrorNotice } from '../../components/ErrorNotice';
import { Pagination } from '../../components/Pagination';
import { StockBadge } from '../../components/StockBadge';
import { showToast } from '../../components/toast';
import { formatMoney } from '../../lib/format';
import { productsQuery } from '../catalog/queries';
import { useAdjustStock } from './mutations';

type Product = components['schemas']['Product'];

/**
 * Inline stock control. Every adjustment is a delta (the API has no "set"),
 * so "set to N" and "make it 1" compute the delta from the current quantity.
 * The Idempotency-Key is reused while the same delta is retried after a failure.
 */
function StockRow({ product }: { readonly product: Product }) {
  const queryClient = useQueryClient();
  const adjust = useAdjustStock(product.id);
  const [amount, setAmount] = useState('1');
  const [info, setInfo] = useState<string | null>(null);
  const attempt = useRef<{ delta: number; key: string } | null>(null);
  const available = product.stock.available;

  function apply(delta: number) {
    setInfo(null);
    if (delta === 0) {
      setInfo('الكمية الحالية تساوي المطلوب، لا حاجة للتعديل.');
      return;
    }
    if (attempt.current?.delta !== delta) attempt.current = { delta, key: crypto.randomUUID() };
    adjust.mutate(
      { body: { delta }, key: attempt.current.key },
      {
        onSuccess: ({ data }) => {
          attempt.current = null;
          showToast(`تم تحديث المخزون إلى ${String(data.available)}`);
          void queryClient.invalidateQueries({ queryKey: ['products'] });
        },
      },
    );
  }

  const n = Math.trunc(Number(amount));
  const validAmount = Number.isInteger(n) && n >= 1 && n <= 1_000_000;
  const busy = adjust.isPending || !validAmount;

  return (
    <tr>
      <td>
        <Link to="/products/$productId" params={{ productId: product.id }}>
          {product.name}
        </Link>
      </td>
      <td>{formatMoney(product.price)}</td>
      <td>
        <StockBadge stock={product.stock} />
      </td>
      <td>
        <div className="stock-control">
          <input
            type="number"
            min={1}
            aria-label={`الكمية للتعديل: ${product.name}`}
            value={amount}
            onChange={(e) => {
              setAmount(e.target.value);
            }}
          />
          <button
            type="button"
            disabled={busy}
            onClick={() => {
              apply(n);
            }}
          >
            +
          </button>
          <button
            type="button"
            disabled={busy}
            onClick={() => {
              apply(-n);
            }}
          >
            −
          </button>
          <button
            type="button"
            disabled={busy || available === null}
            onClick={() => {
              if (available !== null) apply(n - available);
            }}
          >
            تعيين
          </button>
          <button
            type="button"
            disabled={adjust.isPending || available === null}
            onClick={() => {
              if (available !== null) apply(1 - available);
            }}
          >
            اجعل المخزون 1
          </button>
        </div>
        {available === null && <p className="muted">الكمية غير معروفة الآن؛ «تعيين» غير متاح.</p>}
        {info !== null && <p className="muted">{info}</p>}
        {adjust.error !== null && <ErrorNotice error={adjust.error} />}
      </td>
    </tr>
  );
}

export function AdminProducts() {
  const [page, setPage] = useState(1);
  const products = useQuery(productsQuery(undefined, page));

  return (
    <section>
      <h2>٢. المنتجات والمخزون</h2>
      {products.isPending && <p role="status">جارٍ تحميل المنتجات…</p>}
      {products.error !== null && <ErrorNotice error={products.error} />}
      {products.data !== undefined && (
        <>
          {products.data.meta.degraded?.includes('inventory') === true && (
            <p role="alert" className="degraded">
              خدمة المخزون لا تستجيب حالياً؛ الكميات غير معروفة.
            </p>
          )}
          <table>
            <thead>
              <tr>
                <th>المنتج</th>
                <th>السعر</th>
                <th>المخزون الحالي</th>
                <th>تعديل المخزون</th>
              </tr>
            </thead>
            <tbody>
              {products.data.data.map((p) => (
                <StockRow key={p.id} product={p} />
              ))}
            </tbody>
          </table>
          <Pagination
            page={products.data.meta.page}
            lastPage={products.data.meta.last_page}
            onChange={setPage}
          />
        </>
      )}
    </section>
  );
}
