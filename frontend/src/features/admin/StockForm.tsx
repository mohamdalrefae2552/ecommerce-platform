import { useQueryClient } from '@tanstack/react-query';
import { type SyntheticEvent, useRef, useState } from 'react';
import { ErrorNotice } from '../../components/ErrorNotice';
import { Field, serverFieldErrors } from '../../components/forms';
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
        onSuccess: () => {
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
    <section>
      <h2>تعديل المخزون (مسؤول)</h2>
      <form onSubmit={onSubmit} noValidate>
        <Field
          label="التغيير في الكمية (موجب للتوريد، سالب للإنقاص)"
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
        <button type="submit" disabled={adjust.isPending}>
          {adjust.isPending ? 'جارٍ التعديل…' : 'تطبيق'}
        </button>
      </form>
      {adjust.isSuccess && (
        <p role="status">تم التعديل. الكمية الحالية: {adjust.data.data.available}</p>
      )}
      {adjust.error !== null && <ErrorNotice error={adjust.error} />}
    </section>
  );
}
