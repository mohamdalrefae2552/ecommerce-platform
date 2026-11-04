import { useState } from 'react';
import type { components } from '../../api/schema';
import { addToCart } from '../cart/cart';

type Product = components['schemas']['Product'];

export function AddToCartButton({ product }: { readonly product: Product }) {
  const [added, setAdded] = useState(false);
  return (
    <>
      <button
        type="button"
        disabled={product.stock.status === 'out_of_stock'}
        onClick={() => {
          addToCart({
            productId: product.id,
            name: product.name,
            unitPriceMinor: product.price.amount_minor,
          });
          setAdded(true);
        }}
      >
        أضف إلى السلة
      </button>
      {added && <span role="status"> تمت الإضافة إلى السلة.</span>}
    </>
  );
}
