import { z } from 'zod';
import type { components } from './schema';

type ProblemCode = components['schemas']['ProblemCode'];

/**
 * Arabic user message for every problem `code` of the contract
 * (contracts/errors.md). `satisfies Record<ProblemCode, string>` makes a code
 * added to or removed from the contract a compile error here.
 * The UI switches on `code`, never on the server's `detail`.
 */
export const problemMessages = {
  MALFORMED_REQUEST: 'تعذّر قراءة الطلب. حدّث الصفحة وحاول مجدداً.',
  UNAUTHENTICATED: 'يجب تسجيل الدخول للمتابعة.',
  TOKEN_EXPIRED: 'انتهت الجلسة، يرجى تسجيل الدخول من جديد.',
  INVALID_CREDENTIALS: 'البريد الإلكتروني أو كلمة المرور غير صحيحة.',
  REFRESH_TOKEN_INVALID: 'انتهت الجلسة ولم يمكن تجديدها، يرجى تسجيل الدخول من جديد.',
  FORBIDDEN: 'ليست لديك صلاحية لتنفيذ هذه العملية.',
  NOT_FOUND: 'المورد المطلوب غير موجود.',
  METHOD_NOT_ALLOWED: 'هذه العملية غير مدعومة.',
  INSUFFICIENT_STOCK: 'الكمية المتوفرة غير كافية لبعض المنتجات.',
  ORDER_INVALID_TRANSITION: 'لا يمكن نقل الطلب إلى هذه الحالة من حالته الحالية.',
  PAYLOAD_TOO_LARGE: 'حجم البيانات المرسلة أكبر من المسموح.',
  UNSUPPORTED_MEDIA_TYPE: 'نوع الملف أو البيانات المرسلة غير مقبول.',
  VALIDATION_FAILED: 'بعض الحقول غير صحيحة. راجعها وحاول مجدداً.',
  IDEMPOTENCY_KEY_REUSED: 'تغيّر محتوى الطلب أثناء إعادة الإرسال. حدّث السلة وحاول مجدداً.',
  RATE_LIMITED: 'محاولات كثيرة.',
  INTERNAL_ERROR: 'حدث خطأ غير متوقع في الخادم. حاول لاحقاً.',
  INVENTORY_UNAVAILABLE: 'خدمة المخزون غير متاحة حالياً.',
  INVENTORY_TIMEOUT: 'خدمة المخزون لم تستجب في الوقت المحدد.',
} as const satisfies Record<ProblemCode, string>;

/** Shown when a response is not a valid problem (HTML from a proxy, broken JSON, unknown code). */
export const unexpectedResponseMessage = 'وصل رد غير متوقع من الخادم. حاول لاحقاً.';

/** Shown when the server did not answer before the client-side deadline. */
export const requestTimeoutMessage =
  'لم يصل رد الخادم في الوقت المحدد. أعد المحاولة؛ إعادة الإرسال آمنة ولن تكرر العملية.';

/** Shown when the request never got an HTTP response (offline, DNS, connection reset). */
export const networkErrorMessage = 'تعذّر الاتصال بالخادم. تحقّق من الاتصال وحاول مجدداً.';

export function isProblemCode(value: unknown): value is ProblemCode {
  return typeof value === 'string' && Object.hasOwn(problemMessages, value);
}

/**
 * Runtime shape of `components.schemas.Problem` plus the documented
 * extensions (`errors` for 422, `retry_after` for 429/503, `shortages` for
 * 409 INSUFFICIENT_STOCK). Unknown members are dropped.
 */
export const problemSchema = z.object({
  type: z.string(),
  title: z.string(),
  status: z.number().int().min(400).max(599),
  detail: z.string().optional(),
  instance: z.string().optional(),
  code: z.custom<ProblemCode>(isProblemCode, { message: 'unknown problem code' }),
  trace_id: z.string(),
  errors: z.record(z.string(), z.array(z.string()).min(1)).optional(),
  retry_after: z.number().int().min(1).optional(),
  shortages: z
    .array(
      z.object({
        product_id: z.string(),
        requested: z.number().int().min(1),
        available: z.number().int().min(0),
      }),
    )
    .min(1)
    .optional(),
});

export type Problem = z.infer<typeof problemSchema>;
type Shortage = NonNullable<Problem['shortages']>[number];

export function isProblemContentType(contentType: string | null): boolean {
  if (contentType === null) return false;
  const mediaType = contentType.split(';', 1)[0] ?? '';
  return mediaType.trim().toLowerCase() === 'application/problem+json';
}

/** An RFC 9457 error answered by the API (or by `edge`) and validated against the contract. */
export class ProblemError extends Error {
  override readonly name = 'ProblemError';
  readonly problem: Problem;
  /** HTTP status of the response (authoritative over `problem.status`). */
  readonly status: number;
  /** `X-Request-Id` of the response, to quote when reporting the failure. */
  readonly requestId: string | null;

  constructor(problem: Problem, status: number, requestId: string | null) {
    super(problemMessages[problem.code]);
    this.problem = problem;
    this.status = status;
    this.requestId = requestId;
  }

  get code(): ProblemCode {
    return this.problem.code;
  }

  /** Field → messages for `VALIDATION_FAILED`; empty otherwise. */
  get fieldErrors(): Readonly<Record<string, readonly string[]>> {
    return this.problem.errors ?? {};
  }

  /** Seconds to wait (`retry_after` of 429 / 503 problems). */
  get retryAfter(): number | null {
    return this.problem.retry_after ?? null;
  }

  /** Short lines of a 409 `INSUFFICIENT_STOCK`; empty otherwise. */
  get shortages(): readonly Shortage[] {
    return this.problem.shortages ?? [];
  }
}

/** A failed response that is not a valid problem+json (e.g. an HTML 502 from a proxy). */
export class UnexpectedResponseError extends Error {
  override readonly name = 'UnexpectedResponseError';
  readonly status: number;
  readonly requestId: string | null;
  /** Developer-facing reason; never shown to the user. */
  readonly reason: string;

  constructor(status: number, requestId: string | null, reason: string) {
    super(unexpectedResponseMessage);
    this.status = status;
    this.requestId = requestId;
    this.reason = reason;
  }
}

/** The client-side deadline elapsed before the server answered. */
export class RequestTimeoutError extends Error {
  override readonly name = 'RequestTimeoutError';

  constructor(cause: unknown) {
    super(requestTimeoutMessage, { cause });
  }
}

/** The request did not produce an HTTP response at all. */
export class NetworkError extends Error {
  override readonly name = 'NetworkError';

  constructor(cause: unknown) {
    super(networkErrorMessage, { cause });
  }
}

/** "حاول بعد 5 ثوانٍ" style suffix (Arabic plural rules for seconds). */
function retryAfterText(seconds: number): string {
  if (seconds === 1) return 'حاول بعد ثانية واحدة.';
  if (seconds === 2) return 'حاول بعد ثانيتين.';
  if (seconds >= 3 && seconds <= 10) return `حاول بعد ${String(seconds)} ثوانٍ.`;
  return `حاول بعد ${String(seconds)} ثانية.`;
}

const RETRYABLE_CODES: ReadonlySet<ProblemCode> = new Set([
  'RATE_LIMITED',
  'INVENTORY_UNAVAILABLE',
  'INVENTORY_TIMEOUT',
]);

/** Arabic message for anything a query or mutation may throw. */
export function userMessage(error: unknown): string {
  if (error instanceof ProblemError) {
    const wait = error.retryAfter;
    if (wait !== null) return `${error.message} ${retryAfterText(wait)}`;
    return RETRYABLE_CODES.has(error.code) ? `${error.message} حاول بعد قليل.` : error.message;
  }
  if (
    error instanceof UnexpectedResponseError ||
    error instanceof NetworkError ||
    error instanceof RequestTimeoutError
  ) {
    return error.message;
  }
  return unexpectedResponseMessage;
}

/** True when the error means "you are not (or no longer) logged in". */
export function isAuthError(error: unknown): boolean {
  return (
    error instanceof ProblemError &&
    (error.code === 'UNAUTHENTICATED' ||
      error.code === 'TOKEN_EXPIRED' ||
      error.code === 'REFRESH_TOKEN_INVALID')
  );
}
