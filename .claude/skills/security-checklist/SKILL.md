---
name: security-checklist
description: مراجعة أمنية منهجية للـ API وفق OWASP API Top 10 (2023) وضوابط المشروع (JWT، rate limit، ترويسات، BOLA/BFLA، gRPC deadlines). استخدمه في مهام المراجعة الأمنية P*-QA-03 و P3، أو قبل PR يمس المصادقة أو الصلاحيات.
context: fork
agent: code-reviewer
---

# مراجعة أمنية

نفّذ كل بند مع **دليل** (ملف:سطر أو أمر curl/اختبار يعيد الإنتاج). المرجع: `docs/04-security.md`.

## 1. التفويض (API1, API5)
- [ ] كل route في `php artisan route:list --json` مغطى في `AuthorizationMatrixTest`.
- [ ] كل `show/update` يمر بـ Policy؛ كل `index` مقيد في SQL (ابحث عن `::all()`, `->get()` بلا `where user`).
- [ ] جرّب فعلياً: زبون B ⇐ `GET /orders/{A}` ⇒ 404؛ `PATCH status` ⇒ 403؛ `POST /products` ⇒ 403.
## 2. المصادقة (API2)
- [ ] المُحقِّق يثبت EdDSA؛ جرّب توكن `alg:none` و HS256 موقّع بالمفتاح العام ⇒ 401.
- [ ] `aud`, `iss`, `exp`, `nbf` مفروضة؛ الدور من DB.
- [ ] refresh: تدوير، كشف إعادة الاستخدام، كوكي بالسمات الصحيحة، CSRF header + Origin.
- [ ] rate limit: 6 محاولات ⇒ 429؛ `X-Forwarded-For` مزور لا يتجاوزه.
## 3. البيانات (API3)
- [ ] `role`, `user_id`, `status`, `total` غير قابلة للتمرير من المستخدم (grep `$fillable`, FormRequests).
- [ ] Resources لا تُخرج `password`, `token_hash`, مسارات داخلية.
## 4. الموارد (API4, API6)
- [ ] حدود `per_page`، عدد البنود، الكمية، حجم الصورة، `client_max_body_size`.
- [ ] Idempotency على `POST /orders` و `PUT stock`.
## 5. الإعداد (API8)
- [ ] `curl -sI` على `/api/v1/health` و `/`: كل الترويسات موجودة، لا `X-Powered-By` ولا نسخة nginx.
- [ ] `APP_DEBUG=false` في compose غير المحلي؛ 500 بلا trace.
- [ ] الحاويات non-root؛ لا منافذ منشورة غير edge؛ `api` لا يصل لـ `db-inventory`.
## 6. استهلاك الخدمات (API10)
- [ ] كل نداء gRPC بـ timeout (grep في `GrpcInventoryGateway`).
- [ ] ردود gRPC مُتحقق منها.
## 7. الأسرار
- [ ] `git log -p | grep -iE "BEGIN PRIVATE|APP_KEY=base64|password="` لا نتائج حقيقية.
- [ ] `storage/keys` في `.gitignore`.

## المخرج
جدول: البند | النتيجة ✅/❌ | الدليل | الخطورة | الإصلاح. ثم قائمة `[DEFECT]` جاهزة للإرسال.
