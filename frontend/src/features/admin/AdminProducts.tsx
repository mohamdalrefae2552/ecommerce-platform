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
import { useAdjustStock, useUpdateProduct } from './mutations';

type Product = components['schemas']['Product'];

/**
 * Inline edit of the name and the price (PATCH /products/{id}). The list is
 * refetched after saving, so the edit shows at once (the API invalidates its
 * catalogue cache when the change is committed).
 */
function EditProduct({ product }: { readonly product: Product }) {
  const queryClient = useQueryClient();
  const update = useUpdateProduct(product.id);
  const [open, setOpen] = useState(false);
  const [name, setName] = useState(product.name);
  const [price, setPrice] = useState(String(product.price.amount_minor / 100));

  if (!open) {
    return (
      <button
        type="button"
        onClick={() => {
          setName(product.name);
          setPrice(String(product.price.amount_minor / 100));
          setOpen(true);
        }}
      >
        تعديل
      </button>
    );
  }

  const priceMajor = Number(price);
  const trimmed = name.trim();
  const valid = trimmed.length >= 2 && trimmed.length <= 120 && priceMajor > 0;

  return (
    <form
      className="stock-control"
      onSubmit={(e) => {
        e.preventDefault();
        if (!valid) return;
        update.mutate(
          { name: trimmed, price_minor: Math.round(priceMajor * 100) },
          {
            onSuccess: ({ data }) => {
              setOpen(false);
              showToast(`تم حفظ تعديل «${data.name}»`);
              void queryClient.invalidateQueries({ queryKey: ['products'] });
            },
          },
        );
      }}
    >
      <input
        aria-label={`الاسم الجديد: ${product.name}`}
        value={name}
        onChange={(e) => {
          setName(e.target.value);
        }}
      />
      <input
        type="number"
        min={0.01}
        step={0.01}
        aria-label={`السعر الجديد (ل.س): ${product.name}`}
        value={price}
        onChange={(e) => {
          setPrice(e.target.value);
        }}
      />
      <button type="submit" disabled={!valid || update.isPending}>
        حفظ
      </button>
      <button
        type="button"
        onClick={() => {
          setOpen(false);
        }}
      >
        إلغاء
      </button>
      {update.error !== null && <ErrorNotice error={update.error} />}
    </form>
  );
}

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
        </div>
        {available === null && <p className="muted">الكمية غير معروفة الآن؛ «تعيين» غير متاح.</p>}
        {info !== null && <p className="muted">{info}</p>}
        {adjust.error !== null && <ErrorNotice error={adjust.error} />}
      </td>
      <td>
        <EditProduct product={product} />
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
                <th>تعديل المنتج</th>
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
