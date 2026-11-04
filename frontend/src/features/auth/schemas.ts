import { z } from 'zod';

// Same limits as RegisterRequest / LoginRequest in the contract; the server
// re-validates everything (these only give faster feedback).
const email = z
  .string()
  .trim()
  .min(1, 'البريد الإلكتروني مطلوب.')
  .max(254, 'البريد الإلكتروني أطول من 254 حرفاً.')
  .pipe(z.email('البريد الإلكتروني غير صالح.'));

export const loginSchema = z.object({
  email,
  password: z.string().min(1, 'كلمة المرور مطلوبة.').max(128, 'كلمة المرور أطول من 128 حرفاً.'),
});

export const registerSchema = z
  .object({
    name: z
      .string()
      .trim()
      .min(2, 'الاسم يجب أن يكون حرفين على الأقل.')
      .max(100, 'الاسم أطول من 100 حرف.'),
    email,
    password: z
      .string()
      .min(12, 'كلمة المرور يجب أن تكون 12 حرفاً على الأقل.')
      .max(128, 'كلمة المرور أطول من 128 حرفاً.'),
    password_confirmation: z.string(),
  })
  .refine((v) => v.password === v.password_confirmation, {
    path: ['password_confirmation'],
    message: 'تأكيد كلمة المرور لا يطابقها.',
  });

/** `?redirect=` accepts in-app paths only (no `//host` or absolute URLs: open redirect). */
export const redirectSearchSchema = z.object({
  redirect: z
    .string()
    .regex(/^\/(?![/\\])/)
    .optional()
    .catch(undefined),
});
