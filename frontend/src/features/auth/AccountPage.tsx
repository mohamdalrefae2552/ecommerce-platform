import { useQuery } from '@tanstack/react-query';
import { ErrorNotice } from '../../components/ErrorNotice';
import { meQuery } from './queries';

/** Shows the user as the server sees it (role read from the database, not the token). */
export function AccountPage() {
  const { data, error, isPending } = useQuery(meQuery);

  if (isPending) return <p role="status">جارٍ تحميل الحساب…</p>;
  if (error !== null) return <ErrorNotice error={error} />;

  return (
    <section>
      <h1>حسابي</h1>
      <dl>
        <dt>الاسم</dt>
        <dd>{data.name}</dd>
        <dt>البريد الإلكتروني</dt>
        <dd>{data.email}</dd>
        <dt>الدور</dt>
        <dd>{data.role === 'admin' ? 'مسؤول' : 'زبون'}</dd>
      </dl>
    </section>
  );
}
