import { useSyncExternalStore } from 'react';

/** Cart lives in memory only (local state); the server re-checks prices and stock. */
export interface CartLine {
  readonly productId: string;
  readonly name: string;
  readonly unitPriceMinor: number;
  readonly quantity: number;
}

export const MAX_QUANTITY = 10;
export const MAX_LINES = 20;

let lines: readonly CartLine[] = [];
const listeners = new Set<() => void>();

function update(next: readonly CartLine[]): void {
  lines = next;
  for (const listener of listeners) listener();
}

export function getCart(): readonly CartLine[] {
  return lines;
}

export function addToCart(product: Omit<CartLine, 'quantity'>): void {
  const existing = lines.find((l) => l.productId === product.productId);
  if (existing !== undefined) {
    setQuantity(product.productId, existing.quantity + 1);
  } else if (lines.length < MAX_LINES) {
    update([...lines, { ...product, quantity: 1 }]);
  }
}

export function setQuantity(productId: string, quantity: number): void {
  const clamped = Math.min(MAX_QUANTITY, Math.max(1, Math.trunc(quantity) || 1));
  update(lines.map((l) => (l.productId === productId ? { ...l, quantity: clamped } : l)));
}

export function removeFromCart(productId: string): void {
  update(lines.filter((l) => l.productId !== productId));
}

export function clearCart(): void {
  update([]);
}

export function useCart(): readonly CartLine[] {
  return useSyncExternalStore(
    (listener) => {
      listeners.add(listener);
      return () => {
        listeners.delete(listener);
      };
    },
    getCart,
    getCart,
  );
}
