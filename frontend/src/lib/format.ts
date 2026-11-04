import type { components } from '../api/schema';

type Money = components['schemas']['Money'];
type Stock = components['schemas']['Stock'];

/** `amount_minor` is in 1/100 of the currency (contract); shown as SYP. */
export function formatMoney(money: Money): string {
  const major = money.amount_minor / 100;
  return `${major.toLocaleString('en-US', { maximumFractionDigits: 2 })} ل.س`;
}

export function formatMinor(amountMinor: number): string {
  return formatMoney({ amount_minor: amountMinor, currency: 'SYP' });
}

export function formatDate(iso: string): string {
  return new Date(iso).toLocaleString('ar-SY');
}

export const stockUnknownText = 'الكمية غير متاحة حالياً';

export function stockText(stock: Stock): string {
  if (stock.status === 'unknown' || stock.available === null) return stockUnknownText;
  if (stock.status === 'out_of_stock') return 'نفدت الكمية';
  return `المتوفر: ${String(stock.available)}`;
}

/** The API returns absolute URLs; the page is same-origin (edge), so keep only the path. */
export function imagePath(imageUrl: string): string {
  try {
    return new URL(imageUrl).pathname;
  } catch {
    return imageUrl;
  }
}
