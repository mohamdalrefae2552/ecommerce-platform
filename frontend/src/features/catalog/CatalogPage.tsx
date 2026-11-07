import { useQuery } from '@tanstack/react-query';
import { Link, useNavigate } from '@tanstack/react-router';
import { useSession } from '../../auth/session';
import { buttonClass } from '../../components/Button';
import { EmptyState } from '../../components/EmptyState';
import { ErrorNotice } from '../../components/ErrorNotice';
import { PackageIcon } from '../../components/icons';
import { Notice } from '../../components/Notice';
import { Pagination } from '../../components/Pagination';
import { ProductGridSkeleton } from '../../components/Skeleton';
import { cx } from '../../lib/cx';
import { ProductCard } from './ProductCard';
import { categoriesQuery, productsQuery } from './queries';

interface CatalogPageProps {
  readonly category: string | undefined;
  readonly page: number;
}

/** Category filter as links (each one is a shareable URL); the active one is marked. */
function CategoryChips({ category }: { readonly category: string | undefined }) {
  const categories = useQuery(categoriesQuery);
  return (
    <>
      <nav aria-label="التصنيفات" className="chips">
        <Link
          to="/products"
          search={{ page: 1 }}
          className={cx('chip', category === undefined && 'is-active')}
          aria-current={category === undefined ? 'page' : undefined}
        >
          كل التصنيفات
        </Link>
        {categories.data?.map((c) => (
          <Link
            key={c.id}
            to="/products"
            search={{ page: 1, category: c.slug }}
            className={cx('chip', category === c.slug && 'is-active')}
            aria-current={category === c.slug ? 'page' : undefined}
          >
            {c.name}
          </Link>
        ))}
      </nav>
      {categories.error !== null && <ErrorNotice error={categories.error} />}
    </>
  );
}

export function CatalogPage({ category, page }: CatalogPageProps) {
  const navigate = useNavigate({ from: '/products/' });
  const session = useSession();
  const products = useQuery(productsQuery(category, page));
  const canBuy = session.status !== 'authenticated' || session.user.role === 'customer';

  return (
    <section>
      <div className="hero">
        <p className="hero-title">أهلاً بك — تسوّق الآن</p>
        <p className="hero-sub">تشكيلة مختارة من المنتجات بأسعار مناسبة وشحن سريع.</p>
      </div>

      <div className="catalog-head">
        <h1>المنتجات</h1>
        {products.data !== undefined && <p className="muted">{products.data.meta.total} منتج</p>}
      </div>
      <CategoryChips category={category} />

      {products.isPending && <ProductGridSkeleton />}
      {products.error !== null && <ErrorNotice error={products.error} />}
      {products.data !== undefined && (
        <>
          {products.data.meta.degraded?.includes('inventory') === true && (
            <Notice role="alert" tone="warning">
              خدمة المخزون لا تستجيب حالياً؛ المنتجات معروضة دون معرفة الكميات المتوفرة.
            </Notice>
          )}
          {products.data.data.length === 0 ? (
            <EmptyState icon={<PackageIcon />} title="لا توجد منتجات في هذا التصنيف.">
              <Link to="/products" search={{ page: 1 }} className={buttonClass('secondary')}>
                عرض كل المنتجات
              </Link>
            </EmptyState>
          ) : (
            <ul className="product-grid">
              {products.data.data.map((p) => (
                <ProductCard key={p.id} product={p} canBuy={canBuy} />
              ))}
            </ul>
          )}
          <Pagination
            page={products.data.meta.page}
            lastPage={products.data.meta.last_page}
            onChange={(next) => {
              void navigate({ search: (prev) => ({ ...prev, page: next }) });
            }}
          />
        </>
      )}
    </section>
  );
}
