import { useQueryClient } from '@tanstack/react-query';
import { type SyntheticEvent, useRef, useState } from 'react';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import { ErrorNotice } from '../../components/ErrorNotice';
import { Field, serverFieldErrors } from '../../components/forms';
import { Notice } from '../../components/Notice';
import { showToast } from '../../components/toast';
import { catalogKeys } from '../catalog/queries';
import { useAdjustStock } from './mutations';

/**
 * Admin stock adjustment. The Idempotency-Key is kept while the same delta is
 * retried (a timeout retry cannot apply it twice) and renewed after success
 * or when the input changes.
 */
export function StockForm({ productId }: { readonly productId: string }) {
  const queryClient = useQueryClient();
  const adjust = useAdjustStock(productId);
  const [delta, setDelta] = useState('');
  const [note, setNote] = useState('');
  const [clientError, setClientError] = useState<string | null>(null);
  const attempt = useRef<{ signature: string; key: string } | null>(null);

  function onSubmit(event: SyntheticEvent) {
    event.preventDefault();
    const value = Number(delta);
    if (!Number.isInteger(value) || value === 0 || Math.abs(value) > 1_000_000) {
      setClientError('أدخل عدداً صحيحاً غير صفري (موجب للتوريد، سالب للإنقاص).');
      return;
    }
    setClientError(null);
    const signature = `${String(value)}|${note}`;
    if (attempt.current?.signature !== signature) {
      attempt.current = { signature, key: crypto.randomUUID() };
    }
    const body = { delta: value, ...(note.trim() === '' ? {} : { note: note.trim() }) };
    adjust.mutate(
      { body, key: attempt.current.key },
      {
        onSuccess: ({ data }) => {
          showToast(`تم تحديث المخزون إلى ${String(data.available)}`);
          attempt.current = null;
          setDelta('');
          setNote('');
          void queryClient.invalidateQueries({ queryKey: catalogKeys.product(productId) });
        },
      },
    );
  }

  const errors = serverFieldErrors(adjust.error);
  return (
    <Card
      title="تعديل المخزون (مسؤول)"
      description="يُطبَّق التغيير في خدمة المخزون؛ إعادة المحاولة بعد انقطاع لا تطبّقه مرتين."
      className="admin-tools"
    >
      <form onSubmit={onSubmit} noValidate className="form-stack">
        <Field
          label="التغيير في الكمية (موجب للتوريد، سالب للإنقاص)"
          hint="عدد صحيح غير صفري، حتى مليون قطعة في المرة."
          errors={clientError === null ? errors['delta'] : [clientError]}
        >
          {(props) => (
            <input
              {...props}
              type="number"
              value={delta}
              onChange={(e) => {
                setDelta(e.target.value);
              }}
            />
          )}
        </Field>
        <Field label="ملاحظة (اختياري)" errors={errors['note']}>
          {(props) => (
            <input
              {...props}
              type="text"
              maxLength={200}
              value={note}
              onChange={(e) => {
                setNote(e.target.value);
              }}
            />
          )}
        </Field>
        <div className="form-actions">
          <Button type="submit" disabled={adjust.isPending}>
            {adjust.isPending ? 'جارٍ التعديل…' : 'تطبيق'}
          </Button>
        </div>
      </form>
      {adjust.isSuccess && (
        <Notice role="status" tone="success">
          تم التعديل. الكمية الحالية: {adjust.data.data.available}
        </Notice>
      )}
      {adjust.error !== null && <ErrorNotice error={adjust.error} />}
    </Card>
  );
}
