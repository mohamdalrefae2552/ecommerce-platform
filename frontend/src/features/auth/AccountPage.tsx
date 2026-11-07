import { useQuery } from '@tanstack/react-query';
import { Badge } from '../../components/Badge';
import { ErrorNotice } from '../../components/ErrorNotice';
import { UserIcon } from '../../components/icons';
import { PageHeader } from '../../components/PageHeader';
import { ListSkeleton } from '../../components/Skeleton';
import { meQuery } from './queries';

/** Shows the user as the server sees it (role read from the database, not the token). */
export function AccountPage() {
  const { data, error, isPending } = useQuery(meQuery);

  if (isPending) return <ListSkeleton label="جارٍ تحميل الحساب…" rows={3} />;
  if (error !== null) return <ErrorNotice error={error} />;

  const isAdmin = data.role === 'admin';
  return (
    <section>
      <PageHeader title="حسابي" subtitle="بيانات الحساب كما يقرؤها الخادم من قاعدة البيانات." />
      <div className="card account-card">
        <span className="avatar avatar-lg" aria-hidden="true">
          <UserIcon />
        </span>
        <dl className="summary-rows">
          <div>
            <dt>الاسم</dt>
            <dd>{data.name}</dd>
          </div>
          <div>
            <dt>البريد الإلكتروني</dt>
            <dd>
              <bdi>{data.email}</bdi>
            </dd>
          </div>
          <div>
            <dt>الدور</dt>
            <dd>
              <Badge tone={isAdmin ? 'warning' : 'primary'}>{isAdmin ? 'مسؤول' : 'زبون'}</Badge>
            </dd>
          </div>
        </dl>
      </div>
    </section>
  );
}
