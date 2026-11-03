import { createFileRoute } from '@tanstack/react-router';
import { HealthStatus } from '../features/health/HealthStatus';

export const Route = createFileRoute('/')({
  component: HomePage,
});

function HomePage() {
  return (
    <section>
      <h1>المتجر</h1>
      <HealthStatus />
    </section>
  );
}
