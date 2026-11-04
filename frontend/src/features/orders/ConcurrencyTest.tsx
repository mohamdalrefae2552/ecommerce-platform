import { useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '../../api/client';
import { ProblemError, userMessage } from '../../api/problem';
import type { components } from '../../api/schema';
import { stockText } from '../../lib/format';
import { productQuery } from '../catalog/queries';
import { orderKeys } from './queries';

type Product = components['schemas']['Product'];

const CONCURRENT_REQUESTS = 10;

interface Outcome {
  readonly created: number;
  readonly insufficient: number;
  readonly insufficientDetails: readonly string[];
  readonly other: readonly string[];
  readonly durationMs: number;
}

/**
 * Race-condition demo (PROOF-R2-1): 10 orders for 1 unit of this product, sent
 * at the same instant with distinct Idempotency-Keys. With a single unit left
 * exactly one must succeed; the rest are 409 INSUFFICIENT_STOCK.
 */
async function runRace(productId: string): Promise<Outcome> {
  const startedAt = performance.now();
  const results = await Promise.allSettled(
    Array.from({ length: CONCURRENT_REQUESTS }, async () =>
      api.POST('/orders', {
        params: { header: { 'Idempotency-Key': crypto.randomUUID() } },
        body: { items: [{ product_id: productId, quantity: 1 }] },
      }),
    ),
  );
  const durationMs = Math.round(performance.now() - startedAt);

  let created = 0;
  let insufficient = 0;
  const insufficientDetails: string[] = [];
  const other: string[] = [];
  for (const r of results) {
    if (r.status === 'fulfilled') {
      created += 1;
    } else if (r.reason instanceof ProblemError && r.reason.code === 'INSUFFICIENT_STOCK') {
      insufficient += 1;
      for (const s of r.reason.shortages) {
        insufficientDetails.push(`مطلوب ${String(s.requested)}، متوفر ${String(s.available)}`);
      }
    } else if (r.reason instanceof ProblemError) {
      other.push(`${String(r.reason.status)} ${r.reason.code}: ${userMessage(r.reason)}`);
    } else {
      other.push(userMessage(r.reason));
    }
  }
  return { created, insufficient, insufficientDetails, other, durationMs };
}

export function ConcurrencyTest({ product }: { readonly product: Product }) {
  const queryClient = useQueryClient();
  const race = useMutation({
    mutationFn: async () => {
      const outcome = await runRace(product.id);
      const fresh = await queryClient.query({ ...productQuery(product.id), staleTime: 0 });
      return { ...outcome, stockAfter: stockText(fresh.stock) };
    },
    onSettled: () => queryClient.invalidateQueries({ queryKey: orderKeys.all }),
  });
  const outcome = race.data;

  return (
    <fieldset>
      <legend>اختبار التزامن (للتجربة)</legend>
      <p>
        يرسل {CONCURRENT_REQUESTS} طلبات إنشاء طلب في اللحظة نفسها، كل منها بكمية 1 من هذا المنتج.
        اجعل المخزون قطعة واحدة أولاً (من حساب المسؤول)؛ المتوقع أن ينجح طلب واحد فقط. الحد الأقصى
        للطلبات 10 في الدقيقة لكل مستخدم.
      </p>
      <button
        type="button"
        disabled={race.isPending}
        onClick={() => {
          race.mutate();
        }}
      >
        {race.isPending ? 'جارٍ الإرسال…' : 'شغّل اختبار التزامن'}
      </button>
      {race.error !== null && <p role="alert">{userMessage(race.error)}</p>}
      {outcome !== undefined && (
        <table>
          <caption>النتيجة (خلال {outcome.durationMs} مللي ثانية)</caption>
          <tbody>
            <tr>
              <th>نجح (201)</th>
              <td>{outcome.created}</td>
            </tr>
            <tr>
              <th>رُفض: الكمية غير كافية (409)</th>
              <td>
                {outcome.insufficient}
                {outcome.insufficientDetails.length > 0 && (
                  <ul>
                    {[...new Set(outcome.insufficientDetails)].map((d) => (
                      <li key={d}>{d}</li>
                    ))}
                  </ul>
                )}
              </td>
            </tr>
            <tr>
              <th>أخرى</th>
              <td>
                {outcome.other.length}
                {outcome.other.length > 0 && (
                  <ul>
                    {[...new Set(outcome.other)].map((d) => (
                      <li key={d}>{d}</li>
                    ))}
                  </ul>
                )}
              </td>
            </tr>
            <tr>
              <th>المخزون بعد الاختبار</th>
              <td>{outcome.stockAfter}</td>
            </tr>
          </tbody>
        </table>
      )}
    </fieldset>
  );
}
