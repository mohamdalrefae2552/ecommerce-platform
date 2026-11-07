import { useQuery } from '@tanstack/react-query';
import { ErrorNotice } from '../../components/ErrorNotice';
import { healthQuery } from './queries';

export function HealthStatus() {
  const { data, error, isPending } = useQuery(healthQuery);

  if (isPending) {
    return (
      <p role="status" className="health">
        جارٍ التحقق من حالة الخدمة…
      </p>
    );
  }
  if (error !== null) return <ErrorNotice error={error} />;

  const ok = data.checks.inventory === 'ok';
  return (
    <p role="status" className={ok ? 'health health-ok' : 'health health-degraded'}>
      {ok
        ? 'الخدمة تعمل بشكل طبيعي.'
        : 'الخدمة تعمل، لكن خدمة المخزون لا تستجيب حالياً؛ قد لا تظهر الكميات.'}
    </p>
  );
}
