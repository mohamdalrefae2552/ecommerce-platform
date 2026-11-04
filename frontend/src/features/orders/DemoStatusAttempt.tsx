import { ErrorNotice } from '../../components/ErrorNotice';
import { useChangeOrderStatus } from './mutations';

/**
 * Security demo (PROOF-R1-2): a customer tries the admin-only status change.
 * The request is real; the API must answer 403.
 */
export function DemoStatusAttempt({ orderId }: { readonly orderId: string }) {
  const change = useChangeOrderStatus(orderId);
  return (
    <fieldset>
      <legend>محاولة تغيير الحالة (للتجربة)</legend>
      <p>تجربة أمنية: الزبون يحاول نقل طلبه إلى «مؤكد». المتوقع أن يرفض الخادم (403).</p>
      <button
        type="button"
        disabled={change.isPending}
        onClick={() => {
          change.mutate('confirmed');
        }}
      >
        حاول تغيير الحالة إلى مؤكد
      </button>
      {change.error !== null && <ErrorNotice error={change.error} />}
      {change.isSuccess && <p role="alert">تم تغيير الحالة! (هذا لا يجب أن يحدث للزبون)</p>}
    </fieldset>
  );
}
