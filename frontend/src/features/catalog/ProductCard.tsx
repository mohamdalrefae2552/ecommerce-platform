import { Link } from '@tanstack/react-router';
import type { components } from '../../api/schema';
import { StockBadge } from '../../components/StockBadge';
import { formatMoney, imagePath } from '../../lib/format';
import { AddToCartButton } from './AddToCartButton';

type Product = components['schemas']['Product'];

interface ProductCardProps {
  readonly product: Product;
  readonly canBuy: boolean;
}

/** Catalogue tile: the image and the title open the product (one stretched link). */
export function ProductCard({ product, canBuy }: ProductCardProps) {
  return (
    <li className="product-card">
      <div className="product-card-main">
        <div className="product-media">
          <img src={imagePath(product.image_url)} alt={product.name} loading="lazy" />
        </div>
        <div className="product-card-body">
          <p className="product-category">{product.category.name}</p>
          <h2 className="product-name">
            <Link
              to="/products/$productId"
              params={{ productId: product.id }}
              className="stretched-link"
            >
              {product.name}
            </Link>
          </h2>
          <p className="price">{formatMoney(product.price)}</p>
          <StockBadge stock={product.stock} />
        </div>
      </div>
      {canBuy && (
        <div className="product-card-foot">
          <AddToCartButton product={product} />
        </div>
      )}
    </li>
  );
}
