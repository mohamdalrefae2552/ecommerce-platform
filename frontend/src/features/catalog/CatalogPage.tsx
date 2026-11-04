import { useQuery } from '@tanstack/react-query';
import { Link, useNavigate } from '@tanstack/react-router';
import { useSession } from '../../auth/session';
import { ErrorNotice } from '../../components/ErrorNotice';
import { Pagination } from '../../components/Pagination';
import { StockBadge } from '../../components/StockBadge';
import { formatMoney, imagePath } from '../../lib/format';
import { AddToCartButton } from './AddToCartButton';
import { categoriesQuery, productsQuery } from './queries';

interface CatalogPageProps {
  readonly category: string | undefined;
  readonly page: number;
}

export function CatalogPage({ category, page }: CatalogPageProps) {
  const navigate = useNavigate({ from: '/products/' });
  const session = useSession();
  const categories = useQuery(categoriesQuery);
  const products = useQuery(productsQuery(category, page));
  const canBuy = session.status !== 'authenticated' || session.user.role === 'customer';

  return (
    <section>
      <div className="hero">
        <p className="hero-title">أهلاً بك — تسوّق الآن</p>
        <p className="hero-sub">تشكيلة مختارة من المنتجات بأسعار مناسبة وشحن سريع.</p>
      </div>
      <h1>المنتجات</h1>
      <p className="filter-bar">
        <label>
          التصنيف:{' '}
          <select
            value={category ?? ''}
            onChange={(e) => {
              const value = e.target.value;
              void navigate({
                search: { page: 1, ...(value === '' ? {} : { category: value }) },
              });
            }}
          >
            <option value="">كل التصنيفات</option>
            {categories.data?.map((c) => (
              <option key={c.id} value={c.slug}>
                {c.name}
              </option>
            ))}
          </select>
        </label>
      </p>
      {categories.error !== null && <ErrorNotice error={categories.error} />}

      {products.isPending && <p role="status">جارٍ تحميل المنتجات…</p>}
      {products.error !== null && <ErrorNotice error={products.error} />}
      {products.data !== undefined && (
        <>
          {products.data.meta.degraded?.includes('inventory') === true && (
            <p role="alert" className="degraded">
              خدمة المخزون لا تستجيب حالياً؛ المنتجات معروضة دون معرفة الكميات المتوفرة.
            </p>
          )}
          {products.data.data.length === 0 && <p>لا توجد منتجات في هذا التصنيف.</p>}
          <ul className="grid">
            {products.data.data.map((p) => (
              <li key={p.id} className="card">
                <img src={imagePath(p.image_url)} alt={p.name} />
                <div className="card-body">
                  <h2>
                    <Link to="/products/$productId" params={{ productId: p.id }}>
                      {p.name}
                    </Link>
                  </h2>
                  <p className="price">{formatMoney(p.price)}</p>
                  <p className="muted">{p.category.name}</p>
                  <p>
                    <StockBadge stock={p.stock} />
                  </p>
                  {canBuy && <AddToCartButton product={p} />}
                </div>
              </li>
            ))}
          </ul>
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
