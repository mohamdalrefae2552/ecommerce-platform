import { ProblemError, UnexpectedResponseError, userMessage } from '../api/problem';

/** Arabic message for a failed call, with the request id to quote when reporting it. */
export function ErrorNotice({ error }: { readonly error: unknown }) {
  const requestId =
    error instanceof ProblemError || error instanceof UnexpectedResponseError
      ? error.requestId
      : null;

  return (
    <div role="alert">
      <p>{userMessage(error)}</p>
      {requestId !== null && <p>رقم المتابعة: {requestId}</p>}
    </div>
  );
}
