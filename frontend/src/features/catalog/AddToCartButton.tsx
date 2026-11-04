import { useState } from 'react';
import type { components } from '../../api/schema';
import { showToast } from '../../components/toast';
import { addToCart } from '../cart/cart';

type Product = components['schemas']['Product'];

export function AddToCartButton({ product }: { readonly product: Product }) {
  const [added, setAdded] = useState(false);
  return (
    <>
      <button
        type="button"
        className="add-to-cart"
        disabled={product.stock.status === 'out_of_stock'}
        onClick={() => {
          addToCart({
            productId: product.id,
            name: product.name,
            unitPriceMinor: product.price.amount_minor,
          });
          setAdded(true);
          showToast('تمت إضافة المنتج إلى السلة');
        }}
      >
        أضف إلى السلة
      </button>
      {added && (
        <span role="status" className="success">
          {' '}
          تمت الإضافة إلى السلة.
        </span>
      )}
    </>
  );
}
