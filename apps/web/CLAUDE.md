# apps/web — React 19 + TypeScript (المالك: react-engineer)

## المسؤولية
واجهة عربية (RTL) لكل الوظائف الخمس + عرض الإثباتات بوضوح (رسائل الرفض، توقف المستودع، نقص الكمية). التصميم لا يُقيَّم.

## الأوامر
| الأمر | الوظيفة |
|---|---|
| `make verify-web` | ESLint + tsc + Vitest + build |
| `make gen` | تحديث `src/api/schema.d.ts` من العقد |
| `docker compose --profile dev up web-dev` | Vite dev server (proxy `/api` → edge) |
| `VITE_API_MOCKS=1` | تشغيل MSW بدل الـ API |

## الاتفاقيات
- كل نداء عبر `src/api/client.ts`؛ الأخطاء `ProblemError` typed؛ الرسائل من `src/api/problem.ts` حسب `code`.
- حالة الخادم في TanStack Query فقط؛ مفاتيح الاستعلام في `features/<x>/queries.ts`.
- معاملات URL (category, page) تُعرّف بـ Zod في تعريف الـ route.
- access token في الذاكرة؛ لا تخزين للتوكن في المتصفح.
- مكوّن لكل حالة: loading / empty / error / degraded.
- اختبارات المكونات مع MSW handlers مطابقة للعقد (`src/mocks/`).
