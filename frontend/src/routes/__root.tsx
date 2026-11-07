import type { QueryClient } from '@tanstack/react-query';
import { Link, Outlet, createRootRouteWithContext, useNavigate } from '@tanstack/react-router';
import { useSession } from '../auth/session';
import { Badge } from '../components/Badge';
import { Button, buttonClass } from '../components/Button';
import { EmptyState } from '../components/EmptyState';
import { ErrorNotice } from '../components/ErrorNotice';
import {
  CartIcon,
  GridIcon,
  LogoMark,
  LogoutIcon,
  PackageIcon,
  SearchIcon,
  ShieldIcon,
  UserIcon,
} from '../components/icons';
import { Toaster } from '../components/toast';
import { useLogout } from '../features/auth/mutations';
import { clearCart, useCart } from '../features/cart/cart';
import { HealthStatus } from '../features/health/HealthStatus';

interface RouterContext {
  readonly queryClient: QueryClient;
}

export const Route = createRootRouteWithContext<RouterContext>()({
  component: RootLayout,
  notFoundComponent: NotFoundPage,
  errorComponent: ({ error }) => (
    <div className="state-page">
      <ErrorNotice error={error} />
    </div>
  ),
});

function NotFoundPage() {
  return (
    <div className="state-page">
      <EmptyState icon={<SearchIcon />} title="الصفحة غير موجودة" titleAs="h1">
        <p className="empty-code" aria-hidden="true">
          404
        </p>
        <p role="alert" className="empty-text">
          الصفحة المطلوبة غير موجودة.
        </p>
        <Link to="/products" search={{ page: 1 }} className={buttonClass('primary')}>
          العودة إلى المنتجات
        </Link>
      </EmptyState>
    </div>
  );
}

function UserMenu() {
  const session = useSession();
  const logout = useLogout();
  const navigate = useNavigate();

  if (session.status === 'restoring') {
    return <span className="muted header-note">جارٍ التحقق من الجلسة…</span>;
  }
  if (session.status === 'anonymous') {
    return (
      <div className="user-menu">
        <Link to="/login" className={buttonClass('ghost', 'sm')}>
          تسجيل الدخول
        </Link>
        <Link to="/register" className={buttonClass('primary', 'sm')}>
          إنشاء حساب
        </Link>
      </div>
    );
  }

  const isAdmin = session.user.role === 'admin';
  return (
    <div className="user-menu">
      <Link to="/account" className="user-chip">
        <span className="avatar" aria-hidden="true">
          <UserIcon />
        </span>
        <span className="user-name">{session.user.name}</span>
      </Link>
      <Badge tone={isAdmin ? 'warning' : 'primary'}>{isAdmin ? 'مسؤول' : 'زبون'}</Badge>
      <Button
        variant="ghost"
        size="sm"
        icon={<LogoutIcon />}
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
        <span className="hide-sm">تسجيل الخروج</span>
      </Button>
    </div>
  );
}

function SiteHeader() {
  const session = useSession();
  const cart = useCart();
  const isAdmin = session.status === 'authenticated' && session.user.role === 'admin';
  const units = cart.length;

  return (
    <header className="site-header">
      <div className="container header-bar">
        <Link to="/products" search={{ page: 1 }} className="brand">
          <span className="brand-mark" aria-hidden="true">
            <LogoMark />
          </span>
          متجر التجربة
        </Link>

        <nav aria-label="القائمة الرئيسية" className="main-nav">
          <ul>
            <li>
              <Link
                to="/products"
                search={{ page: 1 }}
                activeOptions={{ includeSearch: false }}
                activeProps={{ className: 'is-active' }}
              >
                <GridIcon />
                المنتجات
              </Link>
            </li>
            {session.status === 'authenticated' && (
              <li>
                <Link
                  to="/orders"
                  search={{ page: 1 }}
                  activeOptions={{ includeSearch: false }}
                  activeProps={{ className: 'is-active' }}
                >
                  <PackageIcon />
                  {isAdmin ? 'كل الطلبات' : 'طلباتي'}
                </Link>
              </li>
            )}
            {isAdmin && (
              <li>
                <Link to="/admin" activeProps={{ className: 'is-active' }}>
                  <ShieldIcon />
                  لوحة المسؤول
                </Link>
              </li>
            )}
          </ul>
        </nav>

        <div className="header-actions">
          {!isAdmin && (
            <Link to="/cart" className="cart-link" activeProps={{ className: 'is-active' }}>
              <CartIcon />
              <span className="hide-sm">السلة</span>
              <span
                className={units > 0 ? 'cart-count' : 'cart-count is-empty'}
                aria-label={`${String(units)} في السلة`}
              >
                {units}
              </span>
            </Link>
          )}
          <UserMenu />
        </div>
      </div>
    </header>
  );
}

function RootLayout() {
  return (
    <>
      <SiteHeader />
      <main className="container site-main">
        <Outlet />
      </main>
      <footer className="site-footer">
        <div className="container footer-inner">
          <span className="footer-brand">
            <LogoMark /> متجر التجربة
          </span>
          <HealthStatus />
        </div>
      </footer>
      <Toaster />
    </>
  );
}
