import type { ReactNode } from 'react';
import { cx } from '../lib/cx';
import { AlertIcon, CheckIcon, InfoIcon, WarningIcon } from './icons';

type NoticeTone = 'info' | 'success' | 'warning' | 'danger';

const toneIcon = {
  info: InfoIcon,
  success: CheckIcon,
  warning: WarningIcon,
  danger: AlertIcon,
} as const;

interface NoticeProps {
  readonly tone?: NoticeTone;
  /** 'alert' for failures the user must notice, 'status' for confirmations. */
  readonly role?: 'alert' | 'status';
  readonly className?: string;
  readonly children: ReactNode;
}

/** Soft coloured box with an icon; the children carry the message. */
export function Notice({ tone = 'info', role, className, children }: NoticeProps) {
  const Icon = toneIcon[tone];
  return (
    <div role={role} className={cx('notice', `notice-${tone}`, className)}>
      <Icon className="notice-icon" />
      <div className="notice-body">{children}</div>
    </div>
  );
}
