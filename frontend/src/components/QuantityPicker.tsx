interface QuantityPickerProps {
  readonly label: string;
  readonly value: number;
  readonly min?: number;
  readonly max: number;
  readonly disabled?: boolean;
  readonly onChange: (value: number) => void;
}

/** − / number input / + ; every change is clamped to [min, max]. */
export function QuantityPicker({
  label,
  value,
  min = 1,
  max,
  disabled = false,
  onChange,
}: QuantityPickerProps) {
  const clamp = (n: number) => Math.min(max, Math.max(min, Math.trunc(n) || min));
  return (
    <span className="qty-picker">
      <button
        type="button"
        aria-label={`إنقاص ${label}`}
        disabled={disabled || value <= min}
        onClick={() => {
          onChange(clamp(value - 1));
        }}
      >
        −
      </button>
      <input
        type="number"
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
        aria-label={`زيادة ${label}`}
        disabled={disabled || value >= max}
        onClick={() => {
          onChange(clamp(value + 1));
        }}
      >
        +
      </button>
    </span>
  );
}
