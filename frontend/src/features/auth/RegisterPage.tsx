import { Link, useNavigate } from '@tanstack/react-router';
import { type SyntheticEvent, useState } from 'react';
import { ProblemError } from '../../api/problem';
import { Button } from '../../components/Button';
import { ErrorNotice } from '../../components/ErrorNotice';
import { UserIcon } from '../../components/icons';
import { Field, type FieldErrors, serverFieldErrors, validate } from '../../components/forms';
import { useRegister } from './mutations';
import { registerSchema } from './schemas';

const fields = [
  { name: 'name', label: 'الاسم', type: 'text', autoComplete: 'name' },
  { name: 'email', label: 'البريد الإلكتروني', type: 'email', autoComplete: 'email' },
  {
    name: 'password',
    label: 'كلمة المرور (12 حرفاً على الأقل)',
    type: 'password',
    autoComplete: 'new-password',
  },
  {
    name: 'password_confirmation',
    label: 'تأكيد كلمة المرور',
    type: 'password',
    autoComplete: 'new-password',
  },
] as const;

type FormValues = Record<(typeof fields)[number]['name'], string>;

export function RegisterPage() {
  const navigate = useNavigate();
  const register = useRegister();
  const [values, setValues] = useState<FormValues>({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
  });
  const [clientErrors, setClientErrors] = useState<FieldErrors>({});

  const errors = { ...serverFieldErrors(register.error), ...clientErrors };
  const showNotice =
    register.error !== null &&
    !(register.error instanceof ProblemError && register.error.code === 'VALIDATION_FAILED');

  function onSubmit(event: SyntheticEvent) {
    event.preventDefault();
    const checked = validate(registerSchema, values);
    if (!checked.ok) {
      setClientErrors(checked.errors);
      return;
    }
    setClientErrors({});
    register.mutate(checked.data, {
      onSuccess: () => {
        void navigate({ to: '/login', search: { registered: true } });
      },
    });
  }

  return (
    <section className="auth-page">
      <div className="card auth-card">
        <span className="auth-icon" aria-hidden="true">
          <UserIcon />
        </span>
        <h1>إنشاء حساب</h1>
        <p className="auth-sub">أنشئ حساب زبون لإنشاء الطلبات ومتابعة حالتها.</p>
        {showNotice && <ErrorNotice error={register.error} />}
        <form onSubmit={onSubmit} noValidate className="form-stack">
          {fields.map((field) => (
            <Field key={field.name} label={field.label} errors={errors[field.name]}>
              {(props) => (
                <input
                  {...props}
                  type={field.type}
                  autoComplete={field.autoComplete}
                  value={values[field.name]}
                  onChange={(e) => {
                    setValues((v) => ({ ...v, [field.name]: e.target.value }));
                  }}
                />
              )}
            </Field>
          ))}
          <Button type="submit" size="lg" block disabled={register.isPending}>
            {register.isPending ? 'جارٍ الإنشاء…' : 'إنشاء الحساب'}
          </Button>
        </form>
        <p className="auth-foot">
          لديك حساب؟ <Link to="/login">تسجيل الدخول</Link>
        </p>
      </div>
    </section>
  );
}
