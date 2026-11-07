import { Link, useRouter } from '@tanstack/react-router';
import { type SyntheticEvent, useState } from 'react';
import { ProblemError } from '../../api/problem';
import { Button } from '../../components/Button';
import { ErrorNotice } from '../../components/ErrorNotice';
import { UserIcon } from '../../components/icons';
import { Notice } from '../../components/Notice';
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
    <section className="auth-page">
      <div className="card auth-card">
        <span className="auth-icon" aria-hidden="true">
          <UserIcon />
        </span>
        <h1>تسجيل الدخول</h1>
        <p className="auth-sub">أدخل بياناتك للمتابعة إلى متجر التجربة.</p>
        {registered && (
          <Notice role="status" tone="success">
            تم إنشاء الحساب. سجّل الدخول للمتابعة.
          </Notice>
        )}
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
        <form onSubmit={onSubmit} noValidate className="form-stack">
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
          <Button type="submit" size="lg" block disabled={login.isPending}>
            {login.isPending ? 'جارٍ الدخول…' : 'دخول'}
          </Button>
        </form>
        <p className="auth-foot">
          ليس لديك حساب؟ <Link to="/register">إنشاء حساب</Link>
        </p>
      </div>
    </section>
  );
}
