import type { components } from '../api/schema';
import { stockText } from '../lib/format';

export function StockBadge({ stock }: { readonly stock: components['schemas']['Stock'] }) {
  return <span className={`badge badge-${stock.status}`}>{stockText(stock)}</span>;
}
