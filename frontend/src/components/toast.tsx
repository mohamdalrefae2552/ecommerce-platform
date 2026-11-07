import { useSyncExternalStore } from 'react';
import { AlertIcon, CheckIcon } from './icons';

interface Toast {
  readonly id: number;
  readonly message: string;
  readonly kind: 'success' | 'error';
}

let toasts: readonly Toast[] = [];
let nextId = 1;
const listeners = new Set<() => void>();

function emit(next: readonly Toast[]): void {
  toasts = next;
  for (const listener of listeners) listener();
}

/** Short message shown at the bottom of the page for a few seconds. */
export function showToast(message: string, kind: Toast['kind'] = 'success'): void {
  const id = nextId;
  nextId += 1;
  emit([...toasts, { id, message, kind }]);
  setTimeout(() => {
    emit(toasts.filter((t) => t.id !== id));
  }, 4000);
}

function subscribe(listener: () => void): () => void {
  listeners.add(listener);
  return () => {
    listeners.delete(listener);
  };
}

export function Toaster() {
  const current = useSyncExternalStore(
    subscribe,
    () => toasts,
    () => toasts,
  );
  return (
    <div className="toasts" role="status" aria-live="polite">
      {current.map((t) => (
        <p key={t.id} className={`toast toast-${t.kind}`}>
          {t.kind === 'success' ? <CheckIcon /> : <AlertIcon />}
          {t.message}
        </p>
      ))}
    </div>
  );
}
