import { QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';
import { problemMessages, unexpectedResponseMessage } from '../../api/problem';
import { apiUrl, healthOk, problemResponse } from '../../mocks/handlers';
import { server } from '../../mocks/server';
import { createQueryClient } from '../../queryClient';
import { HealthStatus } from './HealthStatus';

function renderHealth() {
  render(
    <QueryClientProvider client={createQueryClient()}>
      <HealthStatus />
    </QueryClientProvider>,
  );
}

describe('HealthStatus', () => {
  it('shows loading, then the healthy state', async () => {
    renderHealth();

    expect(screen.getByRole('status').textContent).toContain('جارٍ التحقق');
    expect(await screen.findByText('الخدمة تعمل بشكل طبيعي.')).toBeDefined();
  });

  it('shows the degraded state when inventory does not answer', async () => {
    server.use(
      http.get(apiUrl('/health'), () =>
        HttpResponse.json({ ...healthOk, checks: { db: 'ok', inventory: 'degraded' } }),
      ),
    );

    renderHealth();

    expect(await screen.findByText(/خدمة المخزون لا تستجيب/)).toBeDefined();
  });

  it('shows the Arabic message for the problem code and the request id', async () => {
    server.use(
      http.get(apiUrl('/health'), () =>
        problemResponse({
          type: 'https://ecommerce.local/problems/internal-error',
          title: 'Internal error',
          status: 500,
          code: 'INTERNAL_ERROR',
          trace_id: 'req-123',
        }),
      ),
    );

    renderHealth();

    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain(problemMessages.INTERNAL_ERROR);
    expect(alert.textContent).toContain('req-123');
  });

  it('shows a clear message when the error is not a problem response', async () => {
    server.use(
      http.get(
        apiUrl('/health'),
        () =>
          new HttpResponse('<html></html>', {
            status: 502,
            headers: { 'Content-Type': 'text/html' },
          }),
      ),
    );

    renderHealth();

    expect((await screen.findByRole('alert')).textContent).toContain(unexpectedResponseMessage);
  });
});
