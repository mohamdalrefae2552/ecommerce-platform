import { Button } from '../../components/Button';
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
    <div className="status-changer">
      <div className="button-row">
        {next.length === 0 && <span className="muted small">لا انتقالات متاحة</span>}
        {next.map((target) => (
          <Button
            key={target}
            size="sm"
            variant={target === 'cancelled' ? 'danger' : 'secondary'}
            disabled={change.isPending}
            onClick={() => {
              change.mutate(target);
            }}
          >
            {target === 'cancelled' ? 'إلغاء' : `نقل إلى: ${statusLabels[target]}`}
          </Button>
        ))}
      </div>
      {change.error !== null && <ErrorNotice error={change.error} />}
    </div>
  );
}
