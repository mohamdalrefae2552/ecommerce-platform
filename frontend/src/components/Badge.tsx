import type { ReactNode } from 'react';
import { cx } from '../lib/cx';

export type Tone = 'neutral' | 'primary' | 'info' | 'success' | 'warning' | 'danger' | 'violet';

interface BadgeProps {
  readonly tone?: Tone;
  /** Coloured dot before the text (stock and health indicators). */
  readonly dot?: boolean;
  readonly className?: string;
  readonly children: ReactNode;
}

export function Badge({ tone = 'neutral', dot = false, className, children }: BadgeProps) {
  return (
    <span className={cx('badge', `tone-${tone}`, dot && 'badge-dot', className)}>{children}</span>
  );
}
