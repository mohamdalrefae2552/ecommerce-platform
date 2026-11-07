import type { components } from '../api/schema';
import { stockText } from '../lib/format';
import { Badge, type Tone } from './Badge';

type Stock = components['schemas']['Stock'];

const stockTone: Record<Stock['status'], Tone> = {
  in_stock: 'success',
  out_of_stock: 'danger',
  unknown: 'neutral',
};

/** Dot + text: how many units are left, or why it is not known. */
export function StockBadge({ stock }: { readonly stock: Stock }) {
  return (
    <Badge tone={stockTone[stock.status]} dot>
      {stockText(stock)}
    </Badge>
  );
}
