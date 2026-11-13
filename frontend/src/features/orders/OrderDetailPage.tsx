import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { useState } from 'react';
import { ProblemError } from '../../api/problem';
import { useSession } from '../../auth/session';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import { EmptyState } from '../../components/EmptyState';
import { ErrorNotice } from '../../components/ErrorNotice';
import { BackIcon, CopyIcon, SearchIcon } from '../../components/icons';
import { ListSkeleton } from '../../components/Skeleton';
import { StatusPill, statusTone } from '../../components/StatusPill';
import { showToast } from '../../components/toast';
import { formatDate, formatMoney } from '../../lib/format';
import { useLiveEvents } from '../../realtime/useLiveEvents';
import { StatusChanger } from './StatusChanger';
import { ORDER_STATUS_CHANGED, orderChannel, statusChangedSchema } from './live';
import { orderKeys, orderQuery } from './queries';
import { statusLabels } from './status';

function BackLink() {
  return (
    <Link to="/orders" search={{ page: 1 }} className="back-link">
      <BackIcon />
      العودة إلى الطلبات
    </Link>
  );
}

export function OrderDetailPage({ orderId }: { readonly orderId: string }) {
  const session = useSession();
  const { data: order, error, isPending } = useQuery(orderQuery(orderId));
  const isAdmin = session.status === 'authenticated' && session.user.role === 'admin';
  const [copied, setCopied] = useState(false);
  const queryClient = useQueryClient();

  // Requirement 4: the customer sees a status change without refreshing.
  // Only the owner may follow an order, so an admin does not subscribe here
  // (the admin dashboard has its own channel).
  const isCustomer = session.status === 'authenticated' && session.user.role === 'customer';
  useLiveEvents(
    isCustomer ? orderChannel(orderId) : null,
    [ORDER_STATUS_CHANGED],
    (_event, data) => {
      void queryClient.invalidateQueries({ queryKey: orderKeys.all });
      const update = statusChangedSchema.safeParse(data);
      if (update.success) {
        showToast(`تغيّرت حالة طلبك إلى: ${statusLabels[update.data.to]}`);
      }
    },
  );

  if (isPending) return <ListSkeleton label="جارٍ تحميل الطلب…" rows={3} />;
  if (error !== null) {
    const notFound = error instanceof ProblemError && error.code === 'NOT_FOUND';
    return (
      <section>
        <BackLink />
        {notFound && <EmptyState icon={<SearchIcon />} title="الطلب غير موجود" titleAs="h1" />}
        <ErrorNotice
          error={error}
          overrides={{ NOT_FOUND: 'لا يوجد طلب بهذا الرقم ضمن الطلبات التي يحق لك عرضها.' }}
        />
      </section>
    );
  }

  const units = order.items.reduce((sum, item) => sum + item.quantity, 0);
  return (
    <section>
      <BackLink />
      <div className="card order-head">
        <div className="order-head-main">
          <h1 className="order-title">الطلب {order.id}</h1>
          <p className="muted small">أُنشئ في {formatDate(order.created_at)}</p>
        </div>
        <div className="order-head-actions">
          <StatusPill status={order.status} />
          {isCustomer && <span className="live-badge">تحديث مباشر</span>}
          <Button
            variant="secondary"
            size="sm"
            icon={<CopyIcon />}
            onClick={() => {
              void navigator.clipboard.writeText(order.id).then(() => {
                setCopied(true);
                showToast('تم نسخ رقم الطلب');
              });
            }}
          >
            نسخ رقم الطلب
          </Button>
          {copied && (
            <span role="status" className="inline-success">
              تم النسخ.
            </span>
          )}
        </div>
      </div>

      <div className="order-layout">
        <div className="order-main">
          <Card title="المنتجات" className="card-flush">
            <table className="data-table">
              <thead>
                <tr>
                  <th>المنتج</th>
                  <th>سعر الوحدة</th>
                  <th>الكمية</th>
                  <th>الإجمالي</th>
                </tr>
              </thead>
              <tbody>
                {order.items.map((item) => (
                  <tr key={item.product_id}>
                    <td data-label="المنتج">{item.product_name}</td>
                    <td data-label="سعر الوحدة" className="num">
                      {formatMoney(item.unit_price)}
                    </td>
                    <td data-label="الكمية">{item.quantity}</td>
                    <td data-label="الإجمالي" className="num">
                      {formatMoney(item.line_total)}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </Card>

          <Card title="سجل الحالات">
            <ol className="timeline">
              {order.status_history.map((h) => (
                <li key={`${h.at}-${h.to}`} className={`timeline-item tone-${statusTone[h.to]}`}>
                  <span className="timeline-dot" aria-hidden="true" />
                  <p className="timeline-title">
                    {h.from === null ? 'إنشاء' : statusLabels[h.from]} ← {statusLabels[h.to]}
                  </p>
                  <p className="muted small">
                    {h.actor_role === 'admin' ? 'مسؤول' : 'زبون'} — {formatDate(h.at)}
                  </p>
                  {h.note !== null && h.note !== '' && <p className="timeline-note">{h.note}</p>}
                </li>
              ))}
            </ol>
          </Card>
        </div>

        <aside className="order-side">
          <Card title="الملخص">
            <dl className="summary-rows">
              <div>
                <dt>الحالة</dt>
                <dd>{statusLabels[order.status]}</dd>
              </div>
              <div>
                <dt>عدد القطع</dt>
                <dd>{units}</dd>
              </div>
              <div className="summary-total">
                <dt>المجموع</dt>
                <dd>{formatMoney(order.total)}</dd>
              </div>
            </dl>
          </Card>
          {isAdmin && (
            <Card title="تغيير الحالة" description="الانتقالات المسموحة من الحالة الحالية فقط.">
              <StatusChanger orderId={order.id} status={order.status} />
            </Card>
          )}
        </aside>
      </div>
    </section>
  );
}
