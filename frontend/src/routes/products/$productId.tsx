import { createFileRoute } from '@tanstack/react-router';
import { ProductPage } from '../../features/catalog/ProductPage';

export const Route = createFileRoute('/products/$productId')({
  component: ProductRoute,
});

function ProductRoute() {
  const { productId } = Route.useParams();
  return <ProductPage productId={productId} />;
}
