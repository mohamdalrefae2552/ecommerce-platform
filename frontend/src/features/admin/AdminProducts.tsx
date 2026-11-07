import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { useRef, useState } from 'react';
import type { components } from '../../api/schema';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import { ErrorNotice } from '../../components/ErrorNotice';
import { EditIcon, MinusIcon, PlusIcon } from '../../components/icons';
import { Notice } from '../../components/Notice';
import { Pagination } from '../../components/Pagination';
import { ListSkeleton } from '../../components/Skeleton';
import { StockBadge } from '../../components/StockBadge';
import { showToast } from '../../components/toast';
import { formatMoney, imagePath } from '../../lib/format';
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
      <Button
        variant="secondary"
        size="sm"
        icon={<EditIcon />}
        onClick={() => {
          setName(product.name);
          setPrice(String(product.price.amount_minor / 100));
          setOpen(true);
        }}
      >
        تعديل
      </Button>
    );
  }

  const priceMajor = Number(price);
  const trimmed = name.trim();
  const valid = trimmed.length >= 2 && trimmed.length <= 120 && priceMajor > 0;

  return (
    <form
      className="inline-edit"
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
        className="input-sm"
        aria-label={`الاسم الجديد: ${product.name}`}
        value={name}
        onChange={(e) => {
          setName(e.target.value);
        }}
      />
      <input
        className="input-sm input-num"
        type="number"
        min={0.01}
        step={0.01}
        aria-label={`السعر الجديد (ل.س): ${product.name}`}
        value={price}
        onChange={(e) => {
          setPrice(e.target.value);
        }}
      />
      <div className="button-row">
        <Button type="submit" size="sm" disabled={!valid || update.isPending}>
          حفظ
        </Button>
        <Button
          variant="ghost"
          size="sm"
          onClick={() => {
            setOpen(false);
          }}
        >
          إلغاء
        </Button>
      </div>
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
      <td data-label="المنتج">
        <div className="product-cell">
          <img src={imagePath(product.image_url)} alt="" className="thumb" loading="lazy" />
          <Link to="/products/$productId" params={{ productId: product.id }}>
            {product.name}
          </Link>
        </div>
      </td>
      <td data-label="السعر" className="num">
        {formatMoney(product.price)}
      </td>
      <td data-label="المخزون الحالي">
        <StockBadge stock={product.stock} />
      </td>
      <td data-label="تعديل المخزون">
        <div className="stock-control">
          <input
            type="number"
            min={1}
            className="input-sm input-num"
            aria-label={`الكمية للتعديل: ${product.name}`}
            value={amount}
            onChange={(e) => {
              setAmount(e.target.value);
            }}
          />
          <Button
            variant="secondary"
            size="sm"
            className="btn-square"
            aria-label={`إضافة إلى مخزون ${product.name}`}
            disabled={busy}
            onClick={() => {
              apply(n);
            }}
          >
            <PlusIcon />
          </Button>
          <Button
            variant="secondary"
            size="sm"
            className="btn-square"
            aria-label={`إنقاص من مخزون ${product.name}`}
            disabled={busy}
            onClick={() => {
              apply(-n);
            }}
          >
            <MinusIcon />
          </Button>
          <Button
            variant="secondary"
            size="sm"
            disabled={busy || available === null}
            onClick={() => {
              if (available !== null) apply(n - available);
            }}
          >
            تعيين
          </Button>
        </div>
        {available === null && (
          <p className="muted small">الكمية غير معروفة الآن؛ «تعيين» غير متاح.</p>
        )}
        {info !== null && <p className="muted small">{info}</p>}
        {adjust.error !== null && <ErrorNotice error={adjust.error} />}
      </td>
      <td data-label="تعديل المنتج">
        <EditProduct product={product} />
      </td>
    </tr>
  );
}

export function AdminProducts() {
  const [page, setPage] = useState(1);
  const products = useQuery(productsQuery(undefined, page));

  return (
    <Card
      title="٢. المنتجات والمخزون"
      description="«+» و«−» تضيف أو تنقص الكمية المكتوبة، و«تعيين» يجعل المخزون مساوياً لها."
    >
      {products.isPending && <ListSkeleton label="جارٍ تحميل المنتجات…" />}
      {products.error !== null && <ErrorNotice error={products.error} />}
      {products.data !== undefined && (
        <>
          {products.data.meta.degraded?.includes('inventory') === true && (
            <Notice role="alert" tone="warning">
              خدمة المخزون لا تستجيب حالياً؛ الكميات غير معروفة.
            </Notice>
          )}
          <div className="table-wrap">
            <table className="data-table">
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
          </div>
          <Pagination
            page={products.data.meta.page}
            lastPage={products.data.meta.last_page}
            onChange={setPage}
          />
        </>
      )}
    </Card>
  );
}
