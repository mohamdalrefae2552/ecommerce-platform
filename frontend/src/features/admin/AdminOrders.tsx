import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { useState } from 'react';
import { Card } from '../../components/Card';
import { EmptyState } from '../../components/EmptyState';
import { ErrorNotice } from '../../components/ErrorNotice';
import { PackageIcon } from '../../components/icons';
import { Pagination } from '../../components/Pagination';
import { ListSkeleton } from '../../components/Skeleton';
import { StatusPill } from '../../components/StatusPill';
import { showToast } from '../../components/toast';
import { formatDate, formatMoney } from '../../lib/format';
import { useLiveEvents } from '../../realtime/useLiveEvents';
import { StatusChanger } from '../orders/StatusChanger';
import { ADMIN_ORDERS_CHANNEL, ORDER_PLACED, ORDER_STATUS_CHANGED } from '../orders/live';
import { orderKeys, ordersQuery } from '../orders/queries';

export function AdminOrders() {
  const [page, setPage] = useState(1);
  const orders = useQuery(ordersQuery(page, undefined));
  const queryClient = useQueryClient();

  // Requirement 4: a new order (or a status changed by another admin)
  // appears without refreshing the page.
  useLiveEvents(ADMIN_ORDERS_CHANNEL, [ORDER_PLACED, ORDER_STATUS_CHANGED], (event) => {
    void queryClient.invalidateQueries({ queryKey: orderKeys.all });
    if (event === ORDER_PLACED) showToast('وصل طلب جديد');
  });

  return (
    <Card
      title="٣. الطلبات"
      description="أحدث الطلبات مع الانتقالات المسموحة لكل طلب. تتحدّث القائمة مباشرةً عند وصول طلب جديد."
    >
      {orders.isPending && <ListSkeleton label="جارٍ تحميل الطلبات…" />}
      {orders.error !== null && <ErrorNotice error={orders.error} />}
      {orders.data !== undefined && (
        <>
          {orders.data.data.length === 0 ? (
            <EmptyState icon={<PackageIcon />} title="لا توجد طلبات." />
          ) : (
            <div className="table-wrap">
              <table className="data-table">
                <thead>
                  <tr>
                    <th>رقم الطلب</th>
                    <th>الحالة</th>
                    <th>المجموع</th>
                    <th>التاريخ</th>
                    <th>تغيير الحالة</th>
                  </tr>
                </thead>
                <tbody>
                  {orders.data.data.map((o) => (
                    <tr key={o.id}>
                      <td data-label="رقم الطلب">
                        <Link to="/orders/$orderId" params={{ orderId: o.id }} className="mono">
                          {o.id}
                        </Link>
                      </td>
                      <td data-label="الحالة">
                        <StatusPill status={o.status} />
                      </td>
                      <td data-label="المجموع" className="num">
                        {formatMoney(o.total)}
                      </td>
                      <td data-label="التاريخ" className="muted">
                        {formatDate(o.created_at)}
                      </td>
                      <td data-label="تغيير الحالة">
                        <StatusChanger orderId={o.id} status={o.status} />
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          <Pagination
            page={orders.data.meta.page}
            lastPage={orders.data.meta.last_page}
            onChange={setPage}
          />
        </>
      )}
    </Card>
  );
}
