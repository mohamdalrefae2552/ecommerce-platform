import { cx } from '../lib/cx';
import { MinusIcon, PlusIcon } from './icons';

interface QuantityPickerProps {
  readonly label: string;
  readonly value: number;
  readonly min?: number;
  readonly max: number;
  readonly disabled?: boolean;
  readonly size?: 'md' | 'lg';
  readonly onChange: (value: number) => void;
}

/** − / number input / + stepper; every change is clamped to [min, max]. */
export function QuantityPicker({
  label,
  value,
  min = 1,
  max,
  disabled = false,
  size = 'md',
  onChange,
}: QuantityPickerProps) {
  const clamp = (n: number) => Math.min(max, Math.max(min, Math.trunc(n) || min));
  return (
    <span className={cx('stepper', size === 'lg' && 'stepper-lg', disabled && 'is-disabled')}>
      <button
        type="button"
        className="stepper-btn"
        aria-label={`إنقاص ${label}`}
        disabled={disabled || value <= min}
        onClick={() => {
          onChange(clamp(value - 1));
        }}
      >
        <MinusIcon />
      </button>
      <input
        type="number"
        className="stepper-input"
        aria-label={label}
        min={min}
        max={max}
        value={value}
        disabled={disabled}
        onChange={(e) => {
          onChange(clamp(Number(e.target.value)));
        }}
      />
      <button
        type="button"
        className="stepper-btn"
        aria-label={`زيادة ${label}`}
        disabled={disabled || value >= max}
        onClick={() => {
          onChange(clamp(value + 1));
        }}
      >
        <PlusIcon />
      </button>
    </span>
  );
}
