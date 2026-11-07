import { useQuery } from '@tanstack/react-query';
import { Link, useNavigate } from '@tanstack/react-router';
import { useSession } from '../../auth/session';
import { buttonClass } from '../../components/Button';
import { Card } from '../../components/Card';
import { EmptyState } from '../../components/EmptyState';
import { ErrorNotice } from '../../components/ErrorNotice';
import { PackageIcon } from '../../components/icons';
import { PageHeader } from '../../components/PageHeader';
import { Pagination } from '../../components/Pagination';
import { ListSkeleton } from '../../components/Skeleton';
import { StatusPill } from '../../components/StatusPill';
import { cx } from '../../lib/cx';
import { formatDate, formatMoney } from '../../lib/format';
import { StatusChanger } from './StatusChanger';
import { ordersQuery } from './queries';
import { type OrderStatus, allStatuses, statusLabels } from './status';

interface OrdersPageProps {
  readonly page: number;
  readonly status: OrderStatus | undefined;
}

function StatusChips({ status }: { readonly status: OrderStatus | undefined }) {
  return (
    <nav aria-label="تصفية حسب الحالة" className="chips">
      <Link
        to="/orders"
        search={{ page: 1 }}
        className={cx('chip', status === undefined && 'is-active')}
        aria-current={status === undefined ? 'page' : undefined}
      >
        الكل
      </Link>
      {allStatuses.map((s) => (
        <Link
          key={s}
          to="/orders"
          search={{ page: 1, status: s }}
          className={cx('chip', status === s && 'is-active')}
          aria-current={status === s ? 'page' : undefined}
        >
          {statusLabels[s]}
        </Link>
      ))}
    </nav>
  );
}

/** "My orders" for a customer (the API restricts it in SQL); every order for an admin. */
export function OrdersPage({ page, status }: OrdersPageProps) {
  const session = useSession();
  const navigate = useNavigate({ from: '/orders/' });
  const orders = useQuery(ordersQuery(page, status));
  const isAdmin = session.status === 'authenticated' && session.user.role === 'admin';

  return (
    <section>
      <PageHeader
        title={isAdmin ? 'كل الطلبات' : 'طلباتي'}
        subtitle={isAdmin ? 'كل طلبات الزبائن مع أدوات تغيير الحالة.' : 'تابع حالة طلباتك.'}
      />
      <StatusChips status={status} />
      {orders.isPending && <ListSkeleton label="جارٍ تحميل الطلبات…" />}
      {orders.error !== null && <ErrorNotice error={orders.error} />}
      {orders.data !== undefined && (
        <>
          {orders.data.data.length === 0 ? (
            <EmptyState icon={<PackageIcon />} title="لا توجد طلبات.">
              {!isAdmin && (
                <Link to="/products" search={{ page: 1 }} className={buttonClass('primary')}>
                  تصفح المنتجات
                </Link>
              )}
            </EmptyState>
          ) : (
            <Card className="card-flush">
              <table className="data-table">
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
                      <td data-label="رقم الطلب">
                        <Link to="/orders/$orderId" params={{ orderId: o.id }} className="mono">
                          {o.id}
                        </Link>
                      </td>
                      {isAdmin && (
                        <td data-label="الزبون" className="mono muted">
                          {o.user_id}
                        </td>
                      )}
                      <td data-label="الحالة">
                        <StatusPill status={o.status} />
                      </td>
                      <td data-label="المجموع" className="num">
                        {formatMoney(o.total)}
                      </td>
                      <td data-label="عدد المنتجات">{o.item_count}</td>
                      <td data-label="التاريخ" className="muted">
                        {formatDate(o.created_at)}
                      </td>
                      {isAdmin && (
                        <td data-label="تغيير الحالة">
                          <StatusChanger orderId={o.id} status={o.status} />
                        </td>
                      )}
                    </tr>
                  ))}
                </tbody>
              </table>
            </Card>
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
