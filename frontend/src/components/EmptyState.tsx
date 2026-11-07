import type { ReactNode } from 'react';

interface EmptyStateProps {
  readonly icon: ReactNode;
  readonly title: string;
  /** 'h1' when the state is the whole page (not found). */
  readonly titleAs?: 'h1' | 'p';
  readonly children?: ReactNode;
}

/** Centered icon + message for an empty list, a missing page or a failed load. */
export function EmptyState({ icon, title, titleAs: Title = 'p', children }: EmptyStateProps) {
  return (
    <div className="empty-state">
      <span className="empty-icon">{icon}</span>
      <Title className="empty-title">{title}</Title>
      {children}
    </div>
  );
}
