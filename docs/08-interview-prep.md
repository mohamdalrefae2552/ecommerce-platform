# 08 — التحضير للمقابلة (كل عضو يجب أن يجيب عن كل سؤال)

> يُحدَّث في نهاية كل مرحلة بمسارات الملفات الحقيقية. الإجابات مختصرة عمداً؛ اشرحها بكلماتك.

## 0. خطة العرض في المقابلة (بالترتيب)
| # | الأمر | ما يُظهره | النتيجة المقاسة على النظام الحي (2026-11-04) |
|---|---|---|---|
| 1 | `make up` ثم `make ps` | كل الخدمات في حاويات منفصلة وكلها healthy | 10 خدمات healthy خلال 23 ثانية |
| 2 | افتح `http://localhost:8080/docs` | توثيق OpenAPI قابل للتجربة (R1-A4) | — |
| 3 | `make demo-r1` | PROOF-R1-1 (طلب زبون آخر ⇒ 404) و PROOF-R1-2 (زبون يغيّر الحالة ⇒ 403)، JWT، 429، الترويسات | 404 / 403 / المسؤول 200 / بلا توكن 401 |
| 4 | `make race-test` | PROOF-R2-1: طلبات متزامنة على آخر قطعة | 30 طلباً من 10 زبائن ⇒ **201 واحد فقط** و 29×409، المخزون النهائي 0 |
| 5 | `make demo-r2` | PROOF-R2-2: إيقاف/إبطاء المستودع | متوقف ⇒ 503 `INVENTORY_UNAVAILABLE` خلال 0.06s، والمنتج يُعرض بـ `stock.status=unknown`؛ بطء 3s ⇒ 503 `INVENTORY_TIMEOUT` خلال ~3.07s؛ `make chaos-reset` يعيد كل شيء |
| 6 | `docker compose exec api getent hosts db-inventory` | التطبيق لا يرى قاعدة المستودع أصلاً (R2-S2) | يفشل (لا يُحلّ الاسم) |

**ملفات تُفتح عند السؤال:**
- الصلاحيات: `backend/api/app/Modules/Orders/Policies/OrderPolicy.php`، والاختبار الشامل `backend/api/tests/Feature/Security/AuthorizationMatrixTest.php` (كل endpoint × زائر/مالك/زبون آخر/مسؤول).
- إنشاء الطلب (Saga): `backend/api/app/Modules/Orders/Actions/PlaceOrder.php`، واختباره `backend/api/tests/Feature/Orders/CreateOrderSagaTest.php`.
- بوابة gRPC والمهل: `backend/api/app/Shared/Inventory/` (الواجهة `InventoryGateway.php` والتنفيذ في `Grpc/`)، والعقد `contracts/proto/inventory/v1/inventory.proto`.
- الحجز الذري: `backend/inventory/src/main/java/com/ecommerce/inventory/application/ReservationService.java` و `persistence/ReservationRepository.java`، وإثبات السباق على مستوى الخدمة `src/test/java/com/ecommerce/inventory/application/ReservationConcurrencyIT.java` (50 خيطاً على قطعة واحدة ⇒ نجاح واحد).
- المخطط: `backend/inventory/src/main/resources/db/migration/V1__init.sql`.

## المتطلب 1
1. **كيف تمنع زبوناً من فتح طلب غيره؟** Policy `OrderPolicy::view` + استعلام القائمة مقيد بـ `user_id` في SQL. الرفض 404 لا 403 كي لا نكشف وجود الطلب (OWASP). الاختبار: `AuthorizationMatrixTest`. ULID لا يكفي وحده — الأمان من التفويض لا من صعوبة التخمين.
2. **لماذا JWT قصير + refresh؟** الـ access لا يمكن إلغاؤه قبل انتهائه ⇒ نجعله 15 دقيقة. الـ refresh معتم ومخزن مجزّأ ومُدوَّر؛ إعادة استخدام القديم = علامة سرقة ⇒ نلغي العائلة.
3. **لماذا EdDSA لا HS256؟** مفتاح عام للتحقق ⇒ أي خدمة مستقبلية تتحقق دون امتلاك سر التوقيع. والخوارزمية مثبتة ⇒ هجوم `alg:none`/تبديل الخوارزمية مستحيل.
4. **أين يُخزَّن التوكن في المتصفح؟** access في الذاكرة (يحمي من سرقة XSS الدائمة)، refresh في كوكي HttpOnly+SameSite=Strict بمسار ضيق (يحمي من XSS و CSRF) + ترويسة مخصصة + فحص Origin.
5. **لماذا الدور يُقرأ من DB وليس من الـ claim؟** كي يسري سحب صلاحية المسؤول فوراً لا بعد 15 دقيقة.
6. **كيف يعمل rate limiting؟** محددات Laravel مسماة (`login`، `register`، `refresh`، `orders-create`، `api`). الدخول بمفتاحين: (بريد+IP) 5/دقيقة و IP 20/دقيقة ضد رش كلمات المرور؛ التسجيل 5/ساعة لكل IP؛ 429 + Retry-After؛ TrustProxies على edge فقط كي لا يُزوَّر IP بـ X-Forwarded-For.
7. **ما فائدة كل ترويسة أمان؟** nosniff (منع تخمين النوع)، frame-ancestors/X-Frame-Options (clickjacking)، CSP (XSS)، Referrer-Policy (تسريب الروابط)، HSTS (فرض HTTPS)، CORP/COOP (عزل الأصل).
8. **لماذا RFC 9457؟** معيار IETF ⇒ شكل واحد لكل الأخطاء، `code` ثابت للعميل، `trace_id` للربط بالـ logs.
9. **كيف تضمن عدم قراءة وحدة جداول أخرى؟** اختبارات Pest Arch تفشل الـ CI إن استوردت وحدة Models/Actions وحدة أخرى؛ التواصل عبر `Contracts/` و DTOs؛ لا FK بين الوحدات.
10. **لماذا 409 لانتقال حالة خاطئ و 422 للتحقق؟** 422: المدخل نفسه غير صالح. 409: المدخل صالح لكنه يتعارض مع حالة المورد الحالية.

## المتطلب 2
11. **لماذا ينجح طلب واحد فقط على آخر قطعة؟** `SELECT … FOR UPDATE` يسلسل المعاملات على الصف؛ الثانية تنتظر وتعيد قراءة القيمة بعد الـ commit فتجدها 0. + `CHECK (available >= 0)` شبكة أمان. اشرح لماذا check-then-act يفشل.
12. **لماذا ترتيب الأقفال حسب product_id؟** لمنع Deadlock بين طلبين يحجزان نفس المنتجات بترتيب مختلف.
13. **لماذا "الكمية غير كافية" ليست خطأ gRPC؟** هي نتيجة عمل متوقعة؛ رموز الأخطاء للأعطال. هذا يبقي منطق إعادة المحاولة نظيفاً (لا نعيد محاولة INSUFFICIENT_STOCK أبداً).
14. **ماذا لو انتهت المهلة والمستودع كان قد حجز فعلاً؟** النتيجة مجهولة ⇒ نرسل Release فوراً (idempotent). وإن وصل Release قبل Reserve البطيء ⇒ tombstone يمنع الحجز المتأخر. وإن فشل Release ⇒ يبقى السجل `RELEASE_PENDING`. **صراحةً:** المصالحة الدورية (P2-API-05) مؤجلة ولم تُبنَ بعد؛ التصميم موثّق في `docs/05`.
15. **ماذا لو مات PHP بعد الحجز وقبل حفظ الطلب؟** سجل محاولة الحجز (`inventory_reservation_attempts`) يُثبَّت قبل النداء، فلا يضيع أثر الحجز. الإفراج التلقائي عنه هو وظيفة المصالحة المؤجلة (P2-API-05) — قلها بصراحة إن سُئلت.
16. **لماذا لا نحفظ الطلب أولاً ثم نحجز؟** المتطلب صريح: لا يُحفظ الطلب إلا بعد موافقة المستودع. (البديل: طلب `pending_reservation` — مرفوض لمخالفته النص.)
17. **ما الـ deadlines ولماذا هذه القيم؟** GetStock 500ms (قراءة، نتدهور بلطف)، Reserve 2s، Release 1s، ميزانية كلية 3s. المستخدم لا ينتظر أكثر من ~3s أبداً.
18. **ماذا يرى المستخدم عند توقف المستودع؟** صفحة المنتج تعمل والمخزون "غير متاح حالياً"؛ إنشاء الطلب ⇒ رسالة واضحة 503 مع إعادة المحاولة لاحقاً؛ زر الطلب لا يعلق.
19. **لماذا Spring Boot و gRPC؟** Polyglot مطلوب؛ Spring Boot 4.1 فيه دعم gRPC رسمي؛ gRPC: عقد صارم مولّد للطرفين، HTTP/2، deadlines أصيلة.
20. **كيف تضمن أن التطبيق لا يصل لقاعدة المستودع؟** شبكتا Docker منفصلتان: `api` ليس على `inventory-net` أصلاً.
21. **لماذا Idempotency-Key ولماذا reservation_id مختلف عنه؟** المفتاح يمنع تكرار الطلب عند إعادة الإرسال؛ reservation_id جديد لكل محاولة كي يمكن إعادة المحاولة بنفس المفتاح بعد فشل دون الاصطدام بـ tombstone.

22a. **ماذا لو فشل حفظ الطلب بعد الحجز (R2-E1)؟** `PlaceOrder` يلتقط فشل الحفظ ويستدعي `ReleaseStock` بنفس `reservation_id` فوراً؛ مثبت باختبار "release on save failure" في `CreateOrderSagaTest`.
22b. **ما المؤجَّل ولماذا؟** إلغاء الطلب لا يعيد المخزون بعد (P2-API-04) والمصالحة (P2-API-05) — أُجّلا لضيق الوقت؛ لا يمسّان الإثباتات الأربعة.

## العمل الجماعي
22. **أرني PR لزميلك واشرح ما فيه.** (كل عضو يراجع فعلاً PRs الآخر — انظر جدول `07` §5.)
