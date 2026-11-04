import { Link } from '@tanstack/react-router';
import { ProblemError, UnexpectedResponseError, isAuthError, userMessage } from '../api/problem';

interface ErrorNoticeProps {
  readonly error: unknown;
  /** Context-specific text that replaces the generic message for some codes. */
  readonly overrides?: Partial<Record<ProblemError['code'], string>>;
  /** product id to name, to name the short lines of a 409 INSUFFICIENT_STOCK. */
  readonly productNames?: ReadonlyMap<string, string>;
}

/** Arabic message for a failed call, with the request id to quote when reporting it. */
export function ErrorNotice({ error, overrides = {}, productNames }: ErrorNoticeProps) {
  const requestId =
    error instanceof ProblemError || error instanceof UnexpectedResponseError
      ? error.requestId
      : null;
  const override = error instanceof ProblemError ? overrides[error.code] : undefined;
  const shortages = error instanceof ProblemError ? error.shortages : [];

  return (
    <div role="alert">
      <p>{override ?? userMessage(error)}</p>
      {shortages.length > 0 && (
        <ul aria-label="المنتجات ذات الكمية غير الكافية">
          {shortages.map((s) => (
            <li key={s.product_id}>
              {productNames?.get(s.product_id) ?? s.product_id}: المطلوب {s.requested}، المتوفر{' '}
              {s.available}
            </li>
          ))}
        </ul>
      )}
      {isAuthError(error) && (
        <p>
          <Link to="/login">تسجيل الدخول</Link>
        </p>
      )}
      {requestId !== null && <p>رقم المتابعة: {requestId}</p>}
    </div>
  );
}
