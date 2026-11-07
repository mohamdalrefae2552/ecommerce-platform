import type { ButtonHTMLAttributes, ReactNode } from 'react';
import { cx } from '../lib/cx';

type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'danger';
type ButtonSize = 'sm' | 'md' | 'lg';

/** Class list of a button, also used to style router links as buttons. */
export function buttonClass(
  variant: ButtonVariant = 'primary',
  size: ButtonSize = 'md',
  block = false,
): string {
  return cx('btn', `btn-${variant}`, `btn-${size}`, block && 'btn-block');
}

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  readonly variant?: ButtonVariant;
  readonly size?: ButtonSize;
  readonly block?: boolean;
  /** Decorative icon placed before the text. */
  readonly icon?: ReactNode;
}

export function Button({
  variant = 'primary',
  size = 'md',
  block = false,
  icon,
  className,
  type = 'button',
  children,
  ...rest
}: ButtonProps) {
  return (
    <button type={type} className={cx(buttonClass(variant, size, block), className)} {...rest}>
      {icon}
      {children}
    </button>
  );
}
