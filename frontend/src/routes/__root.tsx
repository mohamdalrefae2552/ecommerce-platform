import type { QueryClient } from '@tanstack/react-query';
import { Link, Outlet, createRootRouteWithContext, useNavigate } from '@tanstack/react-router';
import { useSession } from '../auth/session';
import { ErrorNotice } from '../components/ErrorNotice';
import { useCart, clearCart } from '../features/cart/cart';
import { HealthStatus } from '../features/health/HealthStatus';
import { useLogout } from '../features/auth/mutations';

export interface RouterContext {
  readonly queryClient: QueryClient;
}

export const Route = createRootRouteWithContext<RouterContext>()({
  component: RootLayout,
  notFoundComponent: () => <p role="alert">الصفحة المطلوبة غير موجودة.</p>,
  errorComponent: ({ error }) => <ErrorNotice error={error} />,
});

function Navigation() {
  const session = useSession();
  const logout = useLogout();
  const navigate = useNavigate();
  const cart = useCart();

  if (session.status === 'restoring') return <p role="status">جارٍ التحقق من الجلسة…</p>;

  return (
    <nav aria-label="القائمة الرئيسية">
      <ul>
        <li>
          <Link to="/products" search={{ page: 1 }}>
            المنتجات
          </Link>
        </li>
        {session.status !== 'authenticated' || session.user.role === 'customer' ? (
          <li>
            <Link to="/cart">السلة ({cart.length})</Link>
          </li>
        ) : null}
        {session.status === 'anonymous' && (
          <>
            <li>
              <Link to="/login">تسجيل الدخول</Link>
            </li>
            <li>
              <Link to="/register">إنشاء حساب</Link>
            </li>
          </>
        )}
        {session.status === 'authenticated' && (
          <>
            <li>
              <Link to="/orders" search={{ page: 1 }}>
                {session.user.role === 'admin' ? 'كل الطلبات' : 'طلباتي'}
              </Link>
            </li>
            {session.user.role === 'admin' && (
              <li>
                <Link to="/admin/products/new">إضافة منتج</Link>
              </li>
            )}
            <li>
              <Link to="/account">
                {session.user.name} ({session.user.role === 'admin' ? 'مسؤول' : 'زبون'})
              </Link>
            </li>
            <li>
              <button
                type="button"
                disabled={logout.isPending}
                onClick={() => {
                  logout.mutate(undefined, {
                    onSettled: () => {
                      clearCart();
                      void navigate({ to: '/' });
                    },
                  });
                }}
              >
                تسجيل الخروج
              </button>
            </li>
          </>
        )}
      </ul>
    </nav>
  );
}

function RootLayout() {
  return (
    <>
      <header>
        <Navigation />
      </header>
      <main>
        <Outlet />
      </main>
      <footer>
        <HealthStatus />
      </footer>
    </>
  );
}
