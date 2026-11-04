import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { Link } from '@tanstack/react-router';
import { useSession } from '../../auth/session';
import { ProblemError } from '../../api/problem';
import { ErrorNotice } from '../../components/ErrorNotice';
import { formatDate, formatMoney } from '../../lib/format';
import { DemoStatusAttempt } from './DemoStatusAttempt';
import { StatusChanger } from './StatusChanger';
import { orderQuery } from './queries';
import { statusLabels } from './status';

export function OrderDetailPage({ orderId }: { readonly orderId: string }) {
  const session = useSession();
  const { data: order, error, isPending } = useQuery(orderQuery(orderId));
  const isAdmin = session.status === 'authenticated' && session.user.role === 'admin';
  const [copied, setCopied] = useState(false);

  if (isPending) return <p role="status">جارٍ تحميل الطلب…</p>;
  if (error !== null) {
    return (
      <section>
        {error instanceof ProblemError && error.code === 'NOT_FOUND' && <h1>الطلب غير موجود</h1>}
        <ErrorNotice error={error} overrides={{ NOT_FOUND: 'الطلب غير موجود' }} />
        <p>
          <Link to="/orders" search={{ page: 1 }}>
            العودة إلى الطلبات
          </Link>
        </p>
      </section>
    );
  }

  return (
    <section>
      <p>
        <Link to="/orders" search={{ page: 1 }}>
          العودة إلى الطلبات
        </Link>
      </p>
      <h1>الطلب {order.id}</h1>
      <p>
        <button
          type="button"
          onClick={() => {
            void navigator.clipboard.writeText(order.id).then(() => {
              setCopied(true);
            });
          }}
        >
          نسخ رقم الطلب
        </button>
        {copied && (
          <span role="status" className="success">
            {' '}
            تم النسخ.
          </span>
        )}
      </p>
      <div>
        الحالة: <strong>{statusLabels[order.status]}</strong>{' '}
        {isAdmin && <StatusChanger orderId={order.id} status={order.status} />}
      </div>
      {!isAdmin && <DemoStatusAttempt orderId={order.id} />}
      <p>المجموع: {formatMoney(order.total)}</p>
      <h2>المنتجات</h2>
      <table>
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
              <td>{item.product_name}</td>
              <td>{formatMoney(item.unit_price)}</td>
              <td>{item.quantity}</td>
              <td>{formatMoney(item.line_total)}</td>
            </tr>
          ))}
        </tbody>
      </table>
      <h2>سجل الحالات</h2>
      <ol>
        {order.status_history.map((h) => (
          <li key={`${h.at}-${h.to}`}>
            {h.from === null ? 'إنشاء' : statusLabels[h.from]} ← {statusLabels[h.to]} (
            {h.actor_role === 'admin' ? 'مسؤول' : 'زبون'}) — {formatDate(h.at)}
            {h.note !== null && h.note !== '' && ` — ${h.note}`}
          </li>
        ))}
      </ol>
    </section>
  );
}
