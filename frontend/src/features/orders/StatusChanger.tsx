import { ErrorNotice } from '../../components/ErrorNotice';
import { useChangeOrderStatus } from './mutations';
import { type OrderStatus, allowedTransitions, statusLabels } from './status';

/** Admin-only control: one button per transition the state machine allows. */
export function StatusChanger({
  orderId,
  status,
}: {
  readonly orderId: string;
  readonly status: OrderStatus;
}) {
  const change = useChangeOrderStatus(orderId);
  const next = allowedTransitions[status];

  return (
    <div>
      {next.length === 0 && <span>لا انتقالات متاحة</span>}
      {next.map((target) => (
        <button
          key={target}
          type="button"
          disabled={change.isPending}
          onClick={() => {
            change.mutate(target);
          }}
        >
          {target === 'cancelled' ? 'إلغاء' : `نقل إلى: ${statusLabels[target]}`}
        </button>
      ))}
      {change.error !== null && <ErrorNotice error={change.error} />}
    </div>
  );
}
