import { Link, useRouter } from '@tanstack/react-router';
import { type SyntheticEvent, useState } from 'react';
import { ProblemError } from '../../api/problem';
import { ErrorNotice } from '../../components/ErrorNotice';
import { Field, type FieldErrors, serverFieldErrors, validate } from '../../components/forms';
import { useLogin } from './mutations';
import { loginSchema } from './schemas';

interface LoginPageProps {
  readonly redirect: string | undefined;
  readonly registered: boolean;
}

export function LoginPage({ redirect, registered }: LoginPageProps) {
  const router = useRouter();
  const login = useLogin();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [clientErrors, setClientErrors] = useState<FieldErrors>({});

  const errors = { ...serverFieldErrors(login.error), ...clientErrors };
  // Field errors are shown next to the fields; everything else as a notice.
  const showNotice =
    login.error !== null &&
    !(login.error instanceof ProblemError && login.error.code === 'VALIDATION_FAILED');

  function onSubmit(event: SyntheticEvent) {
    event.preventDefault();
    const checked = validate(loginSchema, { email, password });
    if (!checked.ok) {
      setClientErrors(checked.errors);
      return;
    }
    setClientErrors({});
    login.mutate(checked.data, {
      onSuccess: () => {
        router.history.push(redirect ?? '/');
      },
    });
  }

  return (
    <section>
      <h1>تسجيل الدخول</h1>
      {registered && <p role="status">تم إنشاء الحساب. سجّل الدخول للمتابعة.</p>}
      {showNotice && (
        <ErrorNotice
          error={login.error}
          overrides={{
            RATE_LIMITED:
              login.error instanceof ProblemError && login.error.retryAfter !== null
                ? `حاولت كثيراً، انتظر ${String(login.error.retryAfter)} ثانية ثم أعد المحاولة.`
                : 'حاولت كثيراً، انتظر قليلاً ثم أعد المحاولة.',
          }}
        />
      )}
      <form onSubmit={onSubmit} noValidate>
        <Field label="البريد الإلكتروني" errors={errors['email']}>
          {(props) => (
            <input
              {...props}
              type="email"
              autoComplete="username"
              value={email}
              onChange={(e) => {
                setEmail(e.target.value);
              }}
            />
          )}
        </Field>
        <Field label="كلمة المرور" errors={errors['password']}>
          {(props) => (
            <input
              {...props}
              type="password"
              autoComplete="current-password"
              value={password}
              onChange={(e) => {
                setPassword(e.target.value);
              }}
            />
          )}
        </Field>
        <button type="submit" disabled={login.isPending}>
          {login.isPending ? 'جارٍ الدخول…' : 'دخول'}
        </button>
      </form>
      <p>
        ليس لديك حساب؟ <Link to="/register">إنشاء حساب</Link>
      </p>
    </section>
  );
}
