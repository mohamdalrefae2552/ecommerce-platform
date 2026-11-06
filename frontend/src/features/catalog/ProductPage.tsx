import { useQuery } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { useSession } from '../../auth/session';
import { ErrorNotice } from '../../components/ErrorNotice';
import { StockBadge } from '../../components/StockBadge';
import { formatMoney, imagePath } from '../../lib/format';
import { StockForm } from '../admin/StockForm';
import { AddToCartButton } from './AddToCartButton';
import { productQuery } from './queries';

export function ProductPage({ productId }: { readonly productId: string }) {
  const session = useSession();
  const { data: product, error, isPending } = useQuery(productQuery(productId));

  if (isPending) return <p role="status">جارٍ تحميل المنتج…</p>;
  if (error !== null) return <ErrorNotice error={error} />;

  const isAdmin = session.status === 'authenticated' && session.user.role === 'admin';
  return (
    <section>
      <p>
        <Link to="/products" search={{ page: 1 }}>
          العودة إلى المنتجات
        </Link>
      </p>
      <div className="product-layout">
        <img src={imagePath(product.image_url)} alt={product.name} className="detail-img" />
        <div className="product-info">
          <h1>{product.name}</h1>
          <p>{product.description}</p>
          <dl>
            <dt>السعر</dt>
            <dd className="price-big">{formatMoney(product.price)}</dd>
            <dt>التصنيف</dt>
            <dd>{product.category.name}</dd>
            <dt>الكمية</dt>
            <dd>
              <StockBadge stock={product.stock} />
            </dd>
          </dl>
          {product.stock.status === 'unknown' && (
            <p role="alert" className="degraded">
              تعذّر الاستعلام عن المخزون الآن؛ يمكنك المحاولة لاحقاً أو إضافة المنتج وسيتحقق الخادم
              من الكمية عند الطلب.
            </p>
          )}
          {!isAdmin && <AddToCartButton product={product} />}
          {isAdmin && <StockForm productId={product.id} />}
        </div>
      </div>
    </section>
  );
}
