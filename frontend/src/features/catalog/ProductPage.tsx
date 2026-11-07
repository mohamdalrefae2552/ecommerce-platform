import { useQuery } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { useSession } from '../../auth/session';
import { ErrorNotice } from '../../components/ErrorNotice';
import { BackIcon, TagIcon } from '../../components/icons';
import { Notice } from '../../components/Notice';
import { Loading, Skeleton } from '../../components/Skeleton';
import { StockBadge } from '../../components/StockBadge';
import { formatMoney, imagePath } from '../../lib/format';
import { StockForm } from '../admin/StockForm';
import { AddToCartButton } from './AddToCartButton';
import { productQuery } from './queries';

function BackLink() {
  return (
    <Link to="/products" search={{ page: 1 }} className="back-link">
      <BackIcon />
      العودة إلى المنتجات
    </Link>
  );
}

function ProductSkeleton() {
  return (
    <Loading label="جارٍ تحميل المنتج…">
      <div className="product-layout">
        <Skeleton className="sk-media sk-frame" />
        <div className="card product-summary">
          <Skeleton className="sk-line sk-w-40" />
          <Skeleton className="sk-title" />
          <Skeleton className="sk-line sk-w-60" />
          <Skeleton className="sk-line" />
          <Skeleton className="sk-button" />
        </div>
      </div>
    </Loading>
  );
}

export function ProductPage({ productId }: { readonly productId: string }) {
  const session = useSession();
  const { data: product, error, isPending } = useQuery(productQuery(productId));

  if (isPending) return <ProductSkeleton />;
  if (error !== null) {
    return (
      <section>
        <BackLink />
        <ErrorNotice error={error} />
      </section>
    );
  }

  const isAdmin = session.status === 'authenticated' && session.user.role === 'admin';
  return (
    <section>
      <BackLink />
      <div className="product-layout">
        <div className="product-gallery">
          <img src={imagePath(product.image_url)} alt={product.name} />
        </div>
        <div className="product-details">
          <div className="card product-summary">
            <Link
              to="/products"
              search={{ page: 1, category: product.category.slug }}
              className="chip chip-sm"
            >
              <TagIcon />
              {product.category.name}
            </Link>
            <h1 className="product-title">{product.name}</h1>
            <p className="price-big">{formatMoney(product.price)}</p>
            <StockBadge stock={product.stock} />
            {product.stock.status === 'unknown' && (
              <Notice role="alert" tone="warning">
                تعذّر الاستعلام عن المخزون الآن؛ يمكنك المحاولة لاحقاً أو إضافة المنتج وسيتحقق
                الخادم من الكمية عند الطلب.
              </Notice>
            )}
            {!isAdmin && <AddToCartButton product={product} size="lg" />}
            <div className="product-description">
              <h2>الوصف</h2>
              <p>{product.description}</p>
            </div>
          </div>
          {isAdmin && <StockForm productId={product.id} />}
        </div>
      </div>
    </section>
  );
}
