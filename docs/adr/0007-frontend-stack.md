# ADR-0007: الواجهة — React 19 + TanStack Router/Query + عميل مولَّد
**الحالة:** مقبول

## السياق
التصميم لا يُقيَّم، لكن الواجهة تُستخدم لعرض الإثباتات (IDOR، توقف المستودع، الرسائل الواضحة). يجب أن تكون صحيحة وآمنة ومتسقة مع العقد.

## القرار
React 19 + TypeScript strict + Vite 8 · TanStack Router (معاملات بحث typed للتصفية/الترقيم) · TanStack Query v5 (cache/retry/states) · `openapi-typescript` + `openapi-fetch` · React Hook Form + Zod · MSW · Vitest + Testing Library · Playwright. CSS بسيط (CSS Modules أو Tailwind 4 بحد أدنى) واتجاه RTL.
- access token في الذاكرة؛ refresh single-flight عند 401 `TOKEN_EXPIRED`؛ لا retry تلقائي لـ POST.
- `Idempotency-Key` يُولَّد مرة لكل "محاولة checkout" ويُعاد مع إعادة الإرسال.

## البدائل المرفوضة
- **Next.js:** SSR غير مطلوب ويعقّد الأصل الواحد والـ CSP.
- **Redux:** حالة الخادم مكانها Query cache.
- **axios + أنواع يدوية:** انحراف صامت عن العقد.

## العواقب
+ أي تغيير بالعقد يظهر كخطأ ترجمة، تطوير متوازٍ عبر MSW. − منحنى تعلم TanStack Router (مقبول).
