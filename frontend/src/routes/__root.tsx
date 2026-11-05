import type { QueryClient } from '@tanstack/react-query';
import { Link, Outlet, createRootRouteWithContext, useNavigate } from '@tanstack/react-router';
import { useSession } from '../auth/session';
import { ErrorNotice } from '../components/ErrorNotice';
import { Toaster } from '../components/toast';
import { useLogout } from '../features/auth/mutations';
import { clearCart, useCart } from '../features/cart/cart';
import { HealthStatus } from '../features/health/HealthStatus';

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

  const isAdmin = session.status === 'authenticated' && session.user.role === 'admin';

  return (
    <nav aria-label="القائمة الرئيسية">
      <ul>
        <li className="brand">
          <Link to="/products" search={{ page: 1 }}>
            متجر التجربة
          </Link>
        </li>
        <li>
          <Link to="/products" search={{ page: 1 }}>
            المنتجات
          </Link>
        </li>
        {!isAdmin && (
          <li>
            <Link to="/cart">
              السلة
              <span className="cart-count" aria-label={`${String(cart.length)} في السلة`}>
                {cart.length}
              </span>
            </Link>
          </li>
        )}
        {session.status === 'authenticated' && (
          <li>
            <Link to="/orders" search={{ page: 1 }}>
              {isAdmin ? 'كل الطلبات' : 'طلباتي'}
            </Link>
          </li>
        )}
        {isAdmin && (
          <li>
            <Link to="/admin">لوحة المسؤول</Link>
          </li>
        )}
        <li className="spacer" />
        {session.status === 'restoring' && <li className="muted">جارٍ التحقق من الجلسة…</li>}
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
              <Link to="/account">{session.user.name}</Link>{' '}
              <span className={`badge ${isAdmin ? 'badge-admin' : 'badge-customer'}`}>
                {isAdmin ? 'مسؤول' : 'زبون'}
              </span>
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
        <div className="footer-inner">
          <HealthStatus />
        </div>
      </footer>
      <Toaster />
    </>
  );
}
