import type { ReactNode } from 'react';

interface PageHeaderProps {
  readonly title: ReactNode;
  readonly subtitle?: ReactNode;
  readonly actions?: ReactNode;
}

/** The page's <h1> with an optional subtitle and end-aligned actions. */
export function PageHeader({ title, subtitle, actions }: PageHeaderProps) {
  return (
    <div className="page-header">
      <div>
        <h1>{title}</h1>
        {subtitle !== undefined && <p className="page-subtitle">{subtitle}</p>}
      </div>
      {actions !== undefined && <div className="page-actions">{actions}</div>}
    </div>
  );
}
