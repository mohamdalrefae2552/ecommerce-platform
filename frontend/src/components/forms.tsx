import { type ReactNode, useId } from 'react';
import type { z } from 'zod';
import { ProblemError } from '../api/problem';
import { cx } from '../lib/cx';

export type FieldErrors = Readonly<Record<string, readonly string[]>>;

/** Client-side check with the same rules as the contract; field → messages. */
export function validate<T>(
  schema: z.ZodType<T>,
  input: unknown,
): { ok: true; data: T } | { ok: false; errors: FieldErrors } {
  const result = schema.safeParse(input);
  if (result.success) return { ok: true, data: result.data };
  const errors: Record<string, string[]> = {};
  for (const issue of result.error.issues) {
    const key = issue.path.map(String).join('.');
    (errors[key] ??= []).push(issue.message);
  }
  return { ok: false, errors };
}

/** Server-side 422 errors of a mutation, keyed like the request body. */
export function serverFieldErrors(error: unknown): FieldErrors {
  return error instanceof ProblemError ? error.fieldErrors : {};
}

interface FieldProps {
  readonly label: string;
  /** Short help text under the control, read with it by screen readers. */
  readonly hint?: string;
  readonly errors?: readonly string[] | undefined;
  readonly className?: string;
  readonly children: (props: {
    id: string;
    'aria-invalid': boolean;
    'aria-describedby': string | undefined;
  }) => ReactNode;
}

/** Label + control + help text + its error messages, wired for screen readers. */
export function Field({ label, hint, errors = [], className, children }: FieldProps) {
  const id = useId();
  const hintId = `${id}-hint`;
  const errorId = `${id}-errors`;
  const invalid = errors.length > 0;
  const describedBy = cx(hint !== undefined && hintId, invalid && errorId);
  return (
    <div className={cx('field', className)}>
      <label htmlFor={id} className="field-label">
        {label}
      </label>
      {children({
        id,
        'aria-invalid': invalid,
        'aria-describedby': describedBy === '' ? undefined : describedBy,
      })}
      {hint !== undefined && (
        <p id={hintId} className="field-hint">
          {hint}
        </p>
      )}
      {invalid && (
        <ul id={errorId} className="field-errors">
          {errors.map((message) => (
            <li key={message}>{message}</li>
          ))}
        </ul>
      )}
    </div>
  );
}
