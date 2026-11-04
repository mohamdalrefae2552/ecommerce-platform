import { useQuery } from '@tanstack/react-query';
import { Link, useNavigate } from '@tanstack/react-router';
import { useSession } from '../../auth/session';
import { ErrorNotice } from '../../components/ErrorNotice';
import { Pagination } from '../../components/Pagination';
import { formatDate, formatMoney } from '../../lib/format';
import { StatusChanger } from './StatusChanger';
import { ordersQuery } from './queries';
import { type OrderStatus, allStatuses, statusLabels } from './status';

interface OrdersPageProps {
  readonly page: number;
  readonly status: OrderStatus | undefined;
}

/** "My orders" for a customer (the API restricts it in SQL); every order for an admin. */
export function OrdersPage({ page, status }: OrdersPageProps) {
  const session = useSession();
  const navigate = useNavigate({ from: '/orders/' });
  const orders = useQuery(ordersQuery(page, status));
  const isAdmin = session.status === 'authenticated' && session.user.role === 'admin';

  return (
    <section>
      <h1>{isAdmin ? 'كل الطلبات' : 'طلباتي'}</h1>
      <p>
        <label>
          الحالة:{' '}
          <select
            value={status ?? ''}
            onChange={(e) => {
              const value = allStatuses.find((s) => s === e.target.value);
              void navigate({
                search: { page: 1, ...(value === undefined ? {} : { status: value }) },
              });
            }}
          >
            <option value="">الكل</option>
            {allStatuses.map((s) => (
              <option key={s} value={s}>
                {statusLabels[s]}
              </option>
            ))}
          </select>
        </label>
      </p>
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
                  {isAdmin && <th>الزبون</th>}
                  <th>الحالة</th>
                  <th>المجموع</th>
                  <th>عدد المنتجات</th>
                  <th>التاريخ</th>
                  {isAdmin && <th>تغيير الحالة</th>}
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
                    {isAdmin && <td>{o.user_id}</td>}
                    <td>{statusLabels[o.status]}</td>
                    <td>{formatMoney(o.total)}</td>
                    <td>{o.item_count}</td>
                    <td>{formatDate(o.created_at)}</td>
                    {isAdmin && (
                      <td>
                        <StatusChanger orderId={o.id} status={o.status} />
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          )}
          <Pagination
            page={orders.data.meta.page}
            lastPage={orders.data.meta.last_page}
            onChange={(next) => {
              void navigate({ search: (prev) => ({ ...prev, page: next }) });
            }}
          />
        </>
      )}
    </section>
  );
}
