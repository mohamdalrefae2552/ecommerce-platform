import type { QueryClient } from '@tanstack/react-query';
import { Link, Outlet, createRootRouteWithContext } from '@tanstack/react-router';

export interface RouterContext {
  readonly queryClient: QueryClient;
}

export const Route = createRootRouteWithContext<RouterContext>()({
  component: RootLayout,
  notFoundComponent: () => <p role="alert">الصفحة المطلوبة غير موجودة.</p>,
});

function RootLayout() {
  return (
    <>
      <header>
        <nav>
          <Link to="/">المتجر</Link>
        </nav>
      </header>
      <main>
        <Outlet />
      </main>
    </>
  );
}
