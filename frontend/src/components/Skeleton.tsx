import type { ReactNode } from 'react';
import { cx } from '../lib/cx';

/** Grey shimmering placeholder block (hidden from assistive technology). */
export function Skeleton({ className }: { readonly className?: string }) {
  return <span className={cx('skeleton', className)} aria-hidden="true" />;
}

/** Announces the loading text to screen readers while showing placeholders. */
export function Loading({
  label,
  children,
}: {
  readonly label: string;
  readonly children: ReactNode;
}) {
  return (
    <div role="status">
      <span className="sr-only">{label}</span>
      {children}
    </div>
  );
}

export function ProductGridSkeleton({ count = 8 }: { readonly count?: number }) {
  return (
    <Loading label="جارٍ تحميل المنتجات…">
      <div className="product-grid">
        {Array.from({ length: count }, (_, i) => (
          <div key={i} className="product-card">
            <Skeleton className="sk-media" />
            <div className="product-card-body">
              <Skeleton className="sk-line sk-w-40" />
              <Skeleton className="sk-line" />
              <Skeleton className="sk-line sk-w-60" />
              <Skeleton className="sk-button" />
            </div>
          </div>
        ))}
      </div>
    </Loading>
  );
}

export function ListSkeleton({
  label,
  rows = 4,
}: {
  readonly label: string;
  readonly rows?: number;
}) {
  return (
    <Loading label={label}>
      <div className="sk-list">
        {Array.from({ length: rows }, (_, i) => (
          <Skeleton key={i} className="sk-row" />
        ))}
      </div>
    </Loading>
  );
}
