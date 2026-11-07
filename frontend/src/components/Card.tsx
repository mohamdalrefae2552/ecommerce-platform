import type { ReactNode } from 'react';
import { cx } from '../lib/cx';

interface CardProps {
  /** Rendered as the section's <h2>. */
  readonly title?: ReactNode;
  readonly description?: ReactNode;
  /** Controls placed at the end of the title row. */
  readonly actions?: ReactNode;
  readonly className?: string;
  readonly children: ReactNode;
}

/** White surface with an optional title row; a <section> so the title names a region. */
export function Card({ title, description, actions, className, children }: CardProps) {
  return (
    <section className={cx('card', className)}>
      {(title !== undefined || actions !== undefined) && (
        <div className="card-head">
          <div>
            {title !== undefined && <h2 className="card-title">{title}</h2>}
            {description !== undefined && <p className="card-desc">{description}</p>}
          </div>
          {actions}
        </div>
      )}
      {children}
    </section>
  );
}
