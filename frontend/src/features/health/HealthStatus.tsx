import { useQuery } from '@tanstack/react-query';
import { ErrorNotice } from '../../components/ErrorNotice';
import { healthQuery } from './queries';

export function HealthStatus() {
  const { data, error, isPending } = useQuery(healthQuery);

  if (isPending) return <p role="status">جارٍ التحقق من حالة الخدمة…</p>;
  if (error !== null) return <ErrorNotice error={error} />;

  return (
    <p role="status">
      {data.checks.inventory === 'ok'
        ? 'الخدمة تعمل بشكل طبيعي.'
        : 'الخدمة تعمل، لكن خدمة المخزون لا تستجيب حالياً؛ قد لا تظهر الكميات.'}
    </p>
  );
}
