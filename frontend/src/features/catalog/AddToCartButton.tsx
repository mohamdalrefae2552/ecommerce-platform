import { useState } from 'react';
import type { components } from '../../api/schema';
import { QuantityPicker } from '../../components/QuantityPicker';
import { showToast } from '../../components/toast';
import { MAX_QUANTITY, addToCart, useCart } from '../cart/cart';

type Product = components['schemas']['Product'];

export function AddToCartButton({ product }: { readonly product: Product }) {
  const cart = useCart();
  const [quantity, setQuantity] = useState(1);
  const [added, setAdded] = useState(false);

  const outOfStock = product.stock.status === 'out_of_stock';
  const inCart = cart.find((l) => l.productId === product.id)?.quantity ?? 0;
  // Known stock caps the picker; unknown stock leaves it to the server.
  const known = product.stock.status === 'in_stock' ? product.stock.available : null;
  const max = Math.max(1, Math.min(MAX_QUANTITY - inCart, known ?? MAX_QUANTITY));
  const lineFull = inCart >= MAX_QUANTITY;
  const selected = Math.min(quantity, max);

  return (
    <div className="add-to-cart-box">
      <QuantityPicker
        label={`الكمية المطلوبة من ${product.name}`}
        value={selected}
        max={max}
        disabled={outOfStock || lineFull}
        onChange={setQuantity}
      />{' '}
      <button
        type="button"
        className="add-to-cart"
        disabled={outOfStock || lineFull}
        onClick={() => {
          const count = addToCart(
            {
              productId: product.id,
              name: product.name,
              unitPriceMinor: product.price.amount_minor,
            },
            selected,
          );
          if (count === 0) {
            showToast('لا يمكن إضافة المزيد إلى السلة.', 'error');
            return;
          }
          setQuantity(1);
          setAdded(true);
          showToast(`تمت إضافة ${String(count)} إلى السلة`);
        }}
      >
        أضف إلى السلة
      </button>
      {lineFull && (
        <p className="muted">
          وصلت إلى الحد الأقصى ({MAX_QUANTITY}) لهذا المنتج في السلة (الموجود الآن: {inCart}).
        </p>
      )}
      {added && !lineFull && (
        <span role="status" className="success">
          {' '}
          تمت الإضافة إلى السلة.
        </span>
      )}
    </div>
  );
}
