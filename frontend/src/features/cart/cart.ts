import { useSyncExternalStore } from 'react';

/** Cart lives in memory only (local state); the server re-checks prices and stock. */
export interface CartLine {
  readonly productId: string;
  readonly name: string;
  readonly unitPriceMinor: number;
  readonly quantity: number;
}

/** Mirrors the contract: an order line carries 1..100 units. */
export const MAX_QUANTITY = 100;
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

/**
 * Adds `quantity` units (default 1), never exceeding MAX_QUANTITY for the line.
 * Returns how many units were really added (0 when the line is full or the cart has too many lines).
 */
export function addToCart(product: Omit<CartLine, 'quantity'>, quantity = 1): number {
  const wanted = Math.max(1, Math.trunc(quantity) || 1);
  const existing = lines.find((l) => l.productId === product.productId);
  if (existing !== undefined) {
    const next = Math.min(MAX_QUANTITY, existing.quantity + wanted);
    setQuantity(product.productId, next);
    return next - existing.quantity;
  }
  if (lines.length >= MAX_LINES) return 0;
  const added = Math.min(MAX_QUANTITY, wanted);
  update([...lines, { ...product, quantity: added }]);
  return added;
}

export function setQuantity(productId: string, quantity: number): void {
  const clamped = Math.min(MAX_QUANTITY, Math.max(1, Math.trunc(quantity) || 1));
  update(lines.map((l) => (l.productId === productId ? { ...l, quantity: clamped } : l)));
}

/**
 * Lowers each listed line to `available` units; a line with 0 available is removed.
 * Lines not listed stay untouched.
 */
export function applyAvailability(
  shortages: readonly { readonly product_id: string; readonly available: number }[],
): void {
  const availableById = new Map(shortages.map((s) => [s.product_id, s.available]));
  update(
    lines.flatMap((l) => {
      const available = availableById.get(l.productId);
      if (available === undefined) return [l];
      if (available <= 0) return [];
      return [{ ...l, quantity: Math.min(l.quantity, available) }];
    }),
  );
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
