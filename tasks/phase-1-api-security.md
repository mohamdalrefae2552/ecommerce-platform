# المرحلة 1 — المتطلب الأول: الواجهات البرمجية والأمان

> المرجع: `docs/00` (R1-*)، `docs/02`، `docs/04`. كل endpoint مطابق للعقد باختبار.
> التوازي: فريق Backend (API) ‖ فريق Frontend يعمل على MSW من العقد ‖ QA يكتب الإثباتات من العقد ويشغّلها حين يندمج الـ backend.

---
## Backend — API (Laravel)

### P1-API-01 · النواة المشتركة: الأخطاء والترويسات والتتبع
- العضو: A · الفرع: `feat/r1-auth-jwt` · يعتمد على: G0
- النطاق: `app/Shared/Http/**`, `bootstrap/app.php`, `config/cors.php`, `config/trustedproxy` (عبر bootstrap).
- الخطوات:
  1. `ProblemDetails` value object + `ProblemResponse` + renderer مركزي في `withExceptions()` يحوّل: `ValidationException`→422، `AuthenticationException`→401 (+`WWW-Authenticate`)، `AuthorizationException`→403، `ModelNotFoundException`/`NotFoundHttpException`→404، `MethodNotAllowedHttpException`→405، `ThrottleRequestsException`→429، `PostTooLargeException`→413، استثناءات النطاق (`DomainProblem` interface بـ `code()`/`status()`)، وأي شيء آخر→500 بلا تفاصيل.
  2. Middleware: `ForceJsonResponse`، `RequestId` (يقرأ/يولّد ULID، يضعه في Log context والرد)، `SecurityHeaders` (`docs/04` §4، HSTS فقط عند HTTPS).
  3. `TrustProxies` على شبكة `edge` فقط.
- معايير القبول: اختبار لكل نوع استثناء يتحقق من `status` و `code` و `Content-Type: application/problem+json` ومطابقة schema `Problem`؛ `SecurityHeadersTest` لكل ترويسة؛ مسار غير موجود ⇒ 404 problem+json؛ `APP_DEBUG=false` ⇒ 500 بلا trace.

### P1-API-02 · المستخدمون: التسجيل والأدوار وكلمات المرور
- العضو: A · الفرع: `feat/r1-auth-jwt` · يعتمد على: P1-API-01
- النطاق: `app/Modules/Users/**`.
- الخطوات: migration `users` (ulid, name, email unique lower, password, role enum `customer|admin` default customer, disabled_at null, timestamps)؛ `Role` enum في `Shared/Auth`؛ `RegisterUser` action؛ `RegisterRequest` (قواعد `docs/02`، `role` محظور `prohibited`)؛ argon2id؛ `UserResource` بقائمة بيضاء (`id,name,email,role,created_at`)؛ أمر `app:create-admin {email} {--name=}` يطلب كلمة المرور تفاعلياً أو من stdin.
- معايير القبول: 201 + `Location`؛ بريد مكرر 422؛ `role:"admin"` في الطلب ⇒ 422 ولا يصبح admin (`MassAssignmentTest`)؛ `password` لا يظهر في أي رد (اختبار)؛ الهاش يبدأ بـ `$argon2id$`.

### P1-API-03 · مصادقة JWT (Guard + Access + Refresh مُدوَّر)
- العضو: A · الفرع: `feat/r1-auth-jwt` · يعتمد على: P1-API-02
- النطاق: `app/Shared/Auth/**`, `config/jwt.php`, `config/auth.php`, `app/Modules/Users/Http/**`, migration `refresh_tokens`.
- الخطوات (TDD): أمر `jwt:keys` (Ed25519، kid، 0600)؛ `TokenIssuer`، `TokenVerifier` (constraints: SignedWith EdDSA، IssuedBy، PermittedFor، StrictValidAt مع leeway)؛ `JwtGuard` عبر `Auth::extend('jwt')` يحمّل المستخدم من DB ويرفض المعطّل؛ `RefreshTokenService` (issue/rotate/revokeFamily بكشف إعادة الاستخدام، داخل transaction مع `lockForUpdate`)؛ endpoints `login/refresh/logout/me` وفق `docs/02` و `docs/04` §2 (الكوكي، `X-Requested-With`، فحص `Origin`)؛ مساواة التوقيت للبريد غير الموجود.
- معايير القبول (`JwtGuardTest`, `RefreshRotationTest`, `LoginTest`):
  - توقيع معدّل / `alg:none` / `HS256` بمفتاح عام / `aud` خاطئ / `kid` غير معروف / مستخدم معطّل ⇒ 401 `UNAUTHENTICATED`؛ منتهي ⇒ 401 `TOKEN_EXPIRED`.
  - refresh يدوّر (القديم يصبح ملغى، الجديد يعمل)؛ استخدام القديم ⇒ 401 + إلغاء كل العائلة (الجديد أيضاً يفشل).
  - refresh بلا `X-Requested-With: fetch` أو بـ `Origin` أجنبي ⇒ **403 `FORBIDDEN`** (فشل حماية CSRF، لا علاقة له بصلاحية التوكن) ولا يُدوَّر التوكن.
  - logout ⇒ 204 + الكوكي محذوف + العائلة ملغاة.
  - ترويسات الكوكي بالضبط: `HttpOnly; Secure; SameSite=Strict; Path=/api/v1/auth`.

### P1-API-04 · تحديد المحاولات
- العضو: A · الفرع: `feat/r1-auth-jwt` · يعتمد على: P1-API-03
- النطاق: `UsersServiceProvider`, `bootstrap/app.php`, `config/cache.php` (store database + migration).
- الخطوات: المحددات في `docs/04` §3؛ تطبيقها على المسارات؛ 429 problem+json مع `Retry-After` و `retry_after`؛ تصفير عداد البريد عند النجاح.
- معايير القبول (`LoginThrottleTest`): 5 محاولات خاطئة لنفس البريد ⇒ السادسة 429 حتى بكلمة مرور صحيحة؛ 21 محاولة من IP واحد على بريدات مختلفة ⇒ 429؛ `X-Forwarded-For` مزور من خارج edge لا يغير المفتاح؛ بعد انقضاء المدة (`travel()`) يعود الدخول.
- **PR:** `feat/r1-auth-jwt` (P1-API-01..04) ⇒ مراجعة ⇒ دمج.

### P1-API-05 · المنتجات والتصنيفات والصور
- العضو: B · الفرع: `feat/r1-products` · يعتمد على: P1-API-04 (مدموج)
- النطاق: `app/Modules/Products/**`, `config/filesystems.php`.
- الخطوات: migrations `categories` (ulid, slug unique, name) + seeder ثابت، `products` (ulid, category_id FK داخل الوحدة, name, description, price_minor bigint, currency, image_path, is_active, timestamps, index (category_id, created_at))؛ `CreateProduct` action مع معالجة الصورة (`docs/02` Products)؛ `ListProducts` (slug تصنيف غير موجود ⇒ 422 `VALIDATION_FAILED` على `category` — متسق مع قاعدة "لا قص صامت"؛ ترتيب ثابت `created_at desc, id desc`؛ ترقيم بحدود صارمة)؛ `ShowProduct`؛ `ProductCatalog` contract + DTO `ProductSnapshot(id, name, price_minor, currency, is_active)` للوحدات الأخرى؛ Policy: الإنشاء للمسؤول فقط.
  - **المخزون في هذه المرحلة:** حقل `stock` يعود دائماً `{status:"unknown", available:null}`، والحقل `initial_stock` و endpoint `PUT /products/{id}/stock` **غير موجودين** في عقد v1 الآن؛ يضيفهما الـ Lead للعقد في `P2-LEAD-01` (إضافة غير كاسرة). لا تُنفّذ شيئاً منهما هنا.
- معايير القبول: `ImageUploadTest` (ملف نصي بامتداد `.jpg` ⇒ 422؛ صورة > 2MB وجسم < 3MB ⇒ 422 على `image`؛ جسم > 3MB ⇒ 413 problem+json من `edge` — انظر P0-LEAD-06؛ الناتج WebP بلا EXIF؛ الاسم ULID)؛ `PaginationLimitsTest` (`per_page=0|101|abc` ⇒ 422، `meta/links` صحيحة)؛ تصفية بالتصنيف صحيحة؛ زبون/زائر `POST /products` ⇒ 403/401؛ كل رد يطابق العقد؛ Arch test: `Products` لا يستورد من وحدات أخرى.

### P1-API-06 · الطلبات: الإنشاء والعرض وآلة الحالات
- العضو: A · الفرع: `feat/r1-orders-security` · يعتمد على: P1-API-05 (مدموج)
- النطاق: `app/Modules/Orders/**`.
- الخطوات (TDD لآلة الحالات والسياسات):
  1. migrations: `orders` (ulid, user_id, status, total_minor, currency, idempotency_key uuid null, request_hash char(64) null, reservation_id uuid null, timestamps, UNIQUE(user_id, idempotency_key), index(user_id, created_at))، `order_items` (order_id FK, product_id ulid **بلا FK**, product_name, unit_price_minor, quantity)، `order_status_history`.
  2. `OrderStatus` enum + `canTransitionTo()` (الرسم في `docs/02`) — اختبار جدولي لكل زوج (5×5).
  3. `CreateOrder` (نسخة R1 بلا مخزون: تحقق المنتجات عبر `ProductCatalog`، لقطة الأسعار، Idempotency كاملة كما في `docs/05` §3 الخطوة 1)، `ShowOrder`، `ListOrders` (مقيد بالمالك في SQL؛ admin بفلاتر)، `ChangeOrderStatus` (transaction + `lockForUpdate` + history + 409 للانتقال الخاطئ).
  4. `OrderPolicy` (`docs/04` §5) — `view` يرفض بـ `denyAsNotFound()`.
  5. Event `Orders\Contracts\Events\OrderPlaced` و `OrderStatusChanged` (afterCommit).
- معايير القبول (`OrderAccessTest` = PROOF-R1-1/2، `OrderStatusTransitionsTest`, `IdempotencyTest`):
  - B يطلب طلب A ⇒ **404** problem+json؛ A ⇒ 200؛ admin ⇒ 200.
  - B و A يطلبان `PATCH status` ⇒ **403**؛ admin ⇒ 200 والسجل يحتوي actor.
  - `GET /orders` لـ B لا يحتوي أي طلب لـ A؛ وإرسال الزبون لفلتر المسؤول `?user_id=…` ⇒ **422** (`prohibited` لغير المسؤول — لا تجاهل صامت).
  - كل الانتقالات الممنوعة ⇒ 409 `ORDER_INVALID_TRANSITION`.
  - نفس المفتاح نفس الجسم ⇒ 201 ثم 200 بنفس id؛ جسم مختلف ⇒ 422 `IDEMPOTENCY_KEY_REUSED`؛ طلبان متزامنان بنفس المفتاح ⇒ طلب واحد في DB.
  - منتج غير موجود/غير فعّال ⇒ 422 بخطأ على `items.N.product_id`.

### P1-API-07 · مصفوفة الصلاحيات الشاملة
- العضو: A · الفرع: `feat/r1-orders-security` · يعتمد على: P1-API-06
- النطاق: `tests/Feature/Security/AuthorizationMatrixTest.php`.
- الخطوات: dataset يولّد من مصفوفة `docs/02` §3 كل (endpoint × دور × ملكية) مع الرد المتوقع؛ اختبار إضافي يفشل إن وُجد route مسجل غير مغطى بالمصفوفة.
- معايير القبول: الاختبار أخضر ويغطي 100% من الـ routes.
- **PR:** `feat/r1-orders-security` (P1-API-06..07).

### P1-API-08 · الإشعارات
- العضو: B · الفرع: `feat/r1-notifications` · يعتمد على: P1-API-06 (مدموج)
- النطاق: `app/Modules/Notifications/**`.
- الخطوات: listeners لـ `OrderPlaced`/`OrderStatusChanged` (تعتمد على Contracts/Events فقط) ⇒ Laravel Notifications بقناتي database (جدول `notifications` مع ULID) و mail (Mailpit، قالب عربي بسيط)؛ endpoints القائمة و `read` (ملكية: إشعار غيري ⇒ 404)؛ listeners مصممة `ShouldQueue`-ready لكن تعمل sync الآن (وثّق للمتطلب 4).
- معايير القبول: إنشاء طلب ⇒ إشعار database واحد + بريد واحد (`Notification::fake` في unit، و Mailpit API في اختبار تكامل واحد)؛ تغيير الحالة ⇒ إشعار؛ فشل المعاملة ⇒ لا إشعار (afterCommit)؛ مطابقة العقد.

---
## Frontend — WEB (React) (يبدأ فور G0 على MSW)

### P1-WEB-01 · المصادقة في الواجهة
- العضو: B · الفرع: `feat/r1-web` · يعتمد على: G0
- الخطوات: `AuthProvider` (access في الذاكرة، `me` عند الإقلاع عبر refresh صامت)، middleware: عند 401 `TOKEN_EXPIRED` ⇒ refresh **single-flight** (وعد مشترك) ثم إعادة الطلب مرة واحدة؛ عند فشل refresh ⇒ خروج؛ صفحات login/register بـ RHF + Zod مطابقة لقواعد العقد، عرض أخطاء 422 على الحقول، 429 برسالة "حاول بعد X ثانية"؛ guards للمسارات (`customer`, `admin`).
- معايير القبول: Vitest: 3 طلبات متزامنة تتلقى 401 ⇒ refresh واحد فقط؛ access لا يوجد في localStorage/sessionStorage (اختبار)؛ أخطاء الحقول تظهر بالعربية.

### P1-WEB-02 · الكتالوج
- العضو: B · الفرع: `feat/r1-web` · يعتمد على: P1-WEB-01
- الخطوات: قائمة المنتجات مع تصفية التصنيف وترقيم عبر search params typed (Zod)، تفاصيل المنتج، مكوّن `StockBadge` يدعم `in_stock | out_of_stock | unknown`.
- معايير القبول: الرابط `/products?category=x&page=2` قابل للمشاركة ويعيد نفس الحالة؛ حالات loading/empty/error مغطاة باختبارات.

### P1-WEB-03 · السلة والطلبات
- العضو: B · الفرع: `feat/r1-web` · يعتمد على: P1-WEB-02
- الخطوات: سلة محلية (state + sessionStorage للعناصر فقط، لا بيانات حساسة)، checkout بـ `Idempotency-Key` واحد لكل محاولة (يُعاد مع إعادة الإرسال، يتجدد بعد النجاح أو تغيير السلة)، زر معطّل أثناء الإرسال، طلباتي + تفاصيل الطلب + سجل الحالات؛ عرض رسائل `code` بالعربية (خريطة `problem.ts`).
- معايير القبول: النقر المزدوج لا ينشئ طلبين (اختبار MSW يعدّ الطلبات بنفس المفتاح)؛ فتح `/orders/<id لغيري>` يعرض "الطلب غير موجود" (404).

### P1-WEB-04 · لوحة المسؤول
- العضو: B · الفرع: `feat/r1-web` · يعتمد على: P1-WEB-03
- الخطوات: إضافة منتج مع صورة (معاينة، تحقق الحجم/النوع قبل الرفع)، قائمة كل الطلبات بفلتر الحالة، تغيير الحالة بالانتقالات المسموحة فقط (يُحسب من نفس جدول العقد) مع عرض 409.
- معايير القبول: زبون يفتح `/admin` ⇒ يُعاد توجيهه؛ وحتى لو تجاوز الواجهة، الـ API يرفض (يغطيه QA).

### P1-WEB-05 · الإشعارات
- العضو: B · الفرع: `feat/r1-web` · يعتمد على: P1-WEB-01
- الخطوات: قائمة الإشعارات + عداد غير المقروء (polling بسيط 30s الآن؛ اللحظي في المتطلب 4) + تعليم كمقروء.
- **PR:** `feat/r1-web` بعد استبدال MSW بالـ API الحقيقي والتحقق يدوياً + E2E من QA.

---
## Quality — QA

### P1-QA-01 · ملفات HTTP وسكربت demo-r1
- العضو: A · الفرع: `test/r1-security-proofs` · يعتمد على: G0
- النطاق: `tests/security/http/*.http`, `tests/security/scripts/demo-r1.sh` (يُستدعى من `make demo-r1`).
- الخطوات: سيناريوهات `docs/04` §8 كاملة؛ السكربت يطبع لكل خطوة: الطلب المختصر، الرمز المتوقع، الرمز الفعلي، ✅/❌، ويخرج ≠ 0 عند أي فشل؛ يستخدم `jq`؛ بيانات العرض تُنشأ وتُنظف تلقائياً.
- معايير القبول: `make demo-r1` على `main` بعد دمج backend ⇒ كل الخطوات ✅.

### P1-QA-02 · E2E للإثباتات
- العضو: A · الفرع: `test/r1-security-proofs` · يعتمد على: P1-QA-01
- الخطوات: Playwright: `idor.spec.ts` (زبونان، الثاني يفتح رابط طلب الأول ⇒ رسالة "غير موجود" + فحص الرد الشبكي 404)، `admin-only.spec.ts`، `login-throttle.spec.ts`، `auth-refresh.spec.ts` (انتهاء access أثناء الجلسة ⇒ تجديد شفاف).
- معايير القبول: `make verify-e2e` أخضر؛ تقارير HTML كـ artifact في CI.

### P1-QA-03 · مراجعة أمنية مستقلة للمرحلة
- العضو: A/B · يعتمد على: كل مهام P1 مدموجة
- الخطوات: طبّق قائمة الفحص الأمني (OWASP API Top 10، `docs/04-security.md`) على `main`؛ كل ملاحظة ⇒ بلاغ عيب للمالك + مهمة جديدة `P1-FIX-NN`.
- معايير القبول: لا ملاحظات بدرجة High/Critical مفتوحة. ثم **G1**.
