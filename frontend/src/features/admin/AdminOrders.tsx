import { useQuery } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { useState } from 'react';
import { ErrorNotice } from '../../components/ErrorNotice';
import { Pagination } from '../../components/Pagination';
import { formatDate, formatMoney } from '../../lib/format';
import { StatusChanger } from '../orders/StatusChanger';
import { ordersQuery } from '../orders/queries';
import { statusLabels } from '../orders/status';

export function AdminOrders() {
  const [page, setPage] = useState(1);
  const orders = useQuery(ordersQuery(page, undefined));

  return (
    <section>
      <h2>٣. الطلبات</h2>
      {orders.isPending && <p role="status">جارٍ تحميل الطلبات…</p>}
      {orders.error !== null && <ErrorNotice error={orders.error} />}
      {orders.data !== undefined && (
        <>
          {orders.data.data.length === 0 && <p>لا توجد طلبات.</p>}
          {orders.data.data.length > 0 && (
            <table>
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
                    <td>
                      <Link to="/orders/$orderId" params={{ orderId: o.id }}>
                        {o.id}
                      </Link>
                    </td>
                    <td>{statusLabels[o.status]}</td>
                    <td>{formatMoney(o.total)}</td>
                    <td>{formatDate(o.created_at)}</td>
                    <td>
                      <StatusChanger orderId={o.id} status={o.status} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
          <Pagination
            page={orders.data.meta.page}
            lastPage={orders.data.meta.last_page}
            onChange={setPage}
          />
        </>
      )}
    </section>
  );
}
