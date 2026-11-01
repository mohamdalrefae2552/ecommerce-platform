# المرحلة 2 — المتطلب الثاني: التواصل بين الخدمات (gRPC)

> المرجع: `docs/00` (R2-*)، `docs/03`، `docs/05` (المصدر الحاسم للسلوك). كل سطر في مصفوفة الفشل `docs/05` §4 = اختبار.
> التوازي: spring-engineer (الخادم) ‖ laravel-engineer (العميل خلف `InventoryGateway` مع Fake ثم الحقيقي) ‖ react-engineer (UX على MSW) ‖ QA (race + chaos).

---
## Lead

### P2-LEAD-01 · تحديث العقود للمتطلب 2
- العضو: A · الفرع: `chore/p2-contracts` · يعتمد على: G1
- الخطوات: OpenAPI: `initial_stock` (0..1,000,000) في `POST /products`، `PUT /products/{id}/stock`، ردود 409 `INSUFFICIENT_STOCK` و 503 `INVENTORY_UNAVAILABLE|INVENTORY_TIMEOUT` على `POST /orders`، 503 على `PUT stock`، أمثلة `stock.status=unknown`. proto: كما هو (D-01 محسوم)؛ و`meta.degraded` في غلاف القوائم. `make lint-contracts` + `buf breaking` نظيف (إضافات فقط).
- معايير القبول: PR مدموج قبل بدء P2-API-03 و P2-WEB-01.

### P2-LEAD-02 · Toxiproxy وأهداف الفوضى
- العضو: B · الفرع: `test/r2-race-chaos` · يعتمد على: G1
- الخطوات: `make chaos-down` (يعطّل الـ proxy ⇒ UNAVAILABLE)، `make chaos-latency MS=…` (toxic latency downstream)، `make chaos-reset`، `make chaos-status`؛ كلها عبر Toxiproxy HTTP API من `toolbox`.
- معايير القبول: `chaos-latency MS=3000` ثم grpcurl بـ `-max-time 1` ⇒ يفشل بالمهلة؛ `chaos-reset` ⇒ ينجح.

---
## Backend — spring-engineer

### P2-INV-01 · المخطط والمستودعات
- العضو: B · الفرع: `feat/r2-inventory-service` · يعتمد على: G1
- النطاق: `db/migration/V1__init.sql` (حرفياً من `docs/05` §2 + فهارس)، `persistence/**`.
- الخطوات: Flyway؛ `StockRepository` و `ReservationRepository` بـ JdbcClient و SQL صريح (القفل المرتب، `ON CONFLICT`)؛ إعداد `lock_timeout`/`statement_timeout` لكل معاملة (`SET LOCAL`).
- معايير القبول: اختبارات Testcontainers للمستودعات؛ قيد CHECK يرفض السالب (اختبار)؛ لا JPA في classpath.

### P2-INV-02 · GetStock
- العضو: B · الفرع: `feat/r2-inventory-service` · يعتمد على: P2-INV-01
- الخطوات: `StockQueryService` + adapter؛ نفس الترتيب والعدد؛ `tracked=false` لغير المعروف؛ تحقق المدخلات (1..100، ULID، بلا تكرار).
- معايير القبول: اختبارات gRPC in-process لكل حالة مدخلات خاطئة ⇒ `INVALID_ARGUMENT` برسالة الحقل.

### P2-INV-03 · ReserveStock (ذرّي، idempotent، بلا deadlock)
- العضو: B · الفرع: `feat/r2-inventory-service` · يعتمد على: P2-INV-01
- الخطوات (TDD — اكتب اختبارات التزامن **أولاً** وشاهدها تفشل على تنفيذ ساذج): الخوارزمية في `docs/05` §2 حرفياً؛ فحص الـ deadline قبل المعاملة؛ دمج البنود ممنوع (مكرر ⇒ INVALID_ARGUMENT).
- معايير القبول (Testcontainers، Postgres حقيقي):
  - `ReservationConcurrencyIT`: مخزون 1، 50 خيطاً عبر `CountDownLatch` بدء مشترك ⇒ **بالضبط** 1 RESERVED و 49 INSUFFICIENT_STOCK، `available=0`، صف حجز واحد. يتكرر الاختبار 20 مرة (`@RepeatedTest`).
  - `DeadlockFreedomIT`: 100 زوج متزامن (A,B)/(B,A) ⇒ لا `deadlock detected` ولا مهلة.
  - `ReserveAllOrNothingIT`، `ReserveIdempotencyIT`، تعدد البنود مع نقص في بند واحد ⇒ لا تغيير وكل النواقص مُعادة مرتبة.

### P2-INV-04 · ReleaseStock و tombstone
- العضو: B · الفرع: `feat/r2-inventory-service` · يعتمد على: P2-INV-03
- معايير القبول: `ReleaseIdempotencyIT` (إفراج مزدوج لا يضاعف الإعادة)، `ReleaseBeforeReserveIT` (Release ثم Reserve بنفس المعرّف ⇒ `RESERVATION_ALREADY_RELEASED` بلا حجز)، Release متزامن مع Reserve بنفس المعرّف (يُثبت عبر latch) ⇒ حالة متسقة دائماً.

### P2-INV-05 · AdjustStock (إدارة المخزون — D-01 محسوم)
- العضو: B · الفرع: `feat/r2-inventory-service` · يعتمد على: P2-INV-01
- معايير القبول: idempotent بـ `adjustment_id`؛ upsert للمنتج الجديد؛ سالب ⇒ `FAILED_PRECONDITION`؛ متزامن مع Reserve ⇒ لا قيمة سالبة.

### P2-INV-06 · الأخطاء والصلابة والملاحظة
- العضو: B · الفرع: `feat/r2-inventory-service` · يعتمد على: P2-INV-02..05
- الخطوات: `@GrpcAdvice` بالخريطة في `docs/03` §3؛ log JSON لكل RPC (`rpc`, `reservation_id`, `outcome`, `duration_ms`, `request_id`) بلا بيانات حساسة؛ Micrometer metrics جاهزة؛ graceful shutdown يكمل الطلبات الجارية؛ JaCoCo ≥ 85%؛ (موصى) PIT على `ReservationService` ≥ 80%.
- معايير القبول: `make verify-inventory` أخضر؛ استثناء غير متوقع ⇒ `INTERNAL` برسالة عامة والتفاصيل في الـ log فقط (اختبار).
- **PR:** `feat/r2-inventory-service`.

---
## Backend — laravel-engineer

### P2-API-01 · توليد stubs والـ Gateway
- العضو: A · الفرع: `feat/r2-inventory-gateway` · يعتمد على: P2-LEAD-01
- النطاق: `apps/api/generated/grpc/**` (عبر `make gen` فقط)، `app/Shared/Inventory/**`, `config/inventory.php`.
- الخطوات: `InventoryGateway` interface بأساليب نطاق (`stockFor(array $ids): StockLevels`, `reserve(ReservationId, Items): ReservationResult`, `release(ReservationId, ReleaseReason): ReleaseResult`, `adjust(...)`)؛ `GrpcInventoryGateway`: قناة واحدة لكل عملية FPM (lazy singleton)، deadline إلزامي لكل نداء، metadata `x-request-id`، إعادة محاولة Reserve وفق `docs/03` §2 فقط، تحويل الحالات وفق `docs/03` §3، التحقق من الردود (§4)؛ `FakeInventoryGateway` للاختبارات (قابل للبرمجة: نجاح/نقص/مهلة/عطل)؛ ربط في الحاوية حسب `config`.
- معايير القبول: `GrpcInventoryGatewayTest` بعميل stub مزيف لكل رمز gRPC ⇒ الاستثناء الصحيح؛ لا نداء بلا `timeout` (اختبار يفحص الخيارات الممررة)؛ رد مخالف (reservation_id مختلف) ⇒ `InventoryContractViolation`؛ فحص انحراف المولَّد في CI.

### P2-API-02 · المخزون في المنتجات (R2-F2) والتدهور اللطيف
- العضو: A · الفرع: `feat/r2-inventory-gateway` · يعتمد على: P2-API-01
- الخطوات: `ShowProduct` و `ListProducts` ينادون `stockFor` **دفعة واحدة** لكل الصفحة (لا N+1)؛ عند Timeout/Unavailable ⇒ `stock: {status:"unknown", available:null}` والرد 200، وفي القوائم `meta.degraded: ["inventory"]` (يضيفه الـ Lead للعقد في P2-LEAD-01)؛ `POST /products` بـ `initial_stock` ⇒ يُحفظ المنتج أولاً ثم `AdjustStock` بـ `adjustment_id` = UUIDv5(product_id, "initial")؛ إن فشل النداء ⇒ 201 مع `stock.status=unknown` + log warning، والمسؤول يضبط الكمية لاحقاً من `PUT /products/{id}/stock` (لا تُخزَّن الكمية في Laravel مؤقتاً أبداً — المخزون ملك Spring وحده)؛ `PUT /products/{id}/stock` (admin، Idempotency-Key ⇒ `adjustment_id` = UUIDv5(key, product)).
- معايير القبول: `ProductShowDegradedTest` (Fake يرمي Timeout ⇒ 200 و unknown)؛ استعلام gRPC واحد لصفحة من 20 منتجاً (اختبار يعدّ النداءات)؛ مطابقة العقد.
- **PR:** `feat/r2-inventory-gateway` (P2-API-01..02).

### P2-API-03 · Saga إنشاء الطلب
- العضو: A · الفرع: `feat/r2-order-saga` · يعتمد على: P2-API-02 (مدموج)
- النطاق: `app/Modules/Orders/**` + migration `inventory_reservation_attempts` (داخل وحدة Orders).
- الخطوات (TDD لكل صف في الجدول): `CreateOrder` وفق `docs/05` §3 حرفياً؛ ترتيب الخطوات حرج: journal يُثبَّت **قبل** النداء؛ التعويض في `finally`-آمن لا يبتلع الاستثناء الأصلي؛ ربط `orders.reservation_id`.
- معايير القبول (`CreateOrderSagaTest` مع `FakeInventoryGateway`):
  - RESERVED ⇒ 201، attempt=CONFIRMED، order موجود.
  - INSUFFICIENT_STOCK ⇒ 409 + `shortages` مطابقة، لا order، attempt=REJECTED، لا Release.
  - Timeout ⇒ 503 `INVENTORY_TIMEOUT` + `Retry-After`، Release مُستدعى بسبب `RESERVE_OUTCOME_UNKNOWN`، لا order.
  - Unavailable + Release يفشل أيضاً ⇒ 503، attempt=RELEASE_PENDING.
  - `CreateOrderCompensationTest`: حقن فشل في معاملة الطلب (مثلاً trigger أو mock للـ repository) بعد RESERVED ⇒ Release بسبب `ORDER_PERSISTENCE_FAILED`، لا order، 500.
  - زمن الرد عند Timeout ≤ deadline + 300ms (اختبار تكامل مع toxiproxy في P2-QA-02).

### P2-API-04 · الإلغاء يُعيد المخزون
- العضو: A · الفرع: `feat/r2-order-saga` · يعتمد على: P2-API-03
- الخطوات: انتقال `cancelled` ⇒ بعد الـ commit `release(ORDER_CANCELLED)`؛ فشله ⇒ attempt=RELEASE_PENDING؛ الإلغاء لا يفشل بسبب المستودع.
- معايير القبول: `CancelWhileInventoryDownTest`؛ إلغاء مزدوج ⇒ 409 (انتقال من cancelled ممنوع) ولا Release ثانٍ.

### P2-API-05 · المصالحة
- العضو: A · الفرع: `feat/r2-order-saga` · يعتمد على: P2-API-03
- الخطوات: أمر `inventory:reconcile` (`docs/05` §3) + جدولته كل دقيقة بـ `withoutOverlapping()` و `onOneServer()`-ready؛ مخرجات مختصرة؛ خيار `--dry-run`.
- معايير القبول: `ReconcilerTest`: PENDING أقدم من دقيقتين بلا order ⇒ RELEASED؛ PENDING حديث ⇒ لا يُلمس؛ CONFIRMED ⇒ لا يُلمس أبداً؛ RELEASE_PENDING ⇒ RELEASED؛ فشل المستودع ⇒ يبقى للمرة القادمة.
- **PR:** `feat/r2-order-saga` (P2-API-03..05).

---
## Frontend — react-engineer

### P2-WEB-01 · تجربة المخزون والأعطال
- العضو: B · الفرع: `feat/r2-web-stock-ux` · يعتمد على: P2-LEAD-01
- الخطوات: `StockBadge` بالحالات الثلاث ("متوفر: 3" / "نفد" / "الكمية غير متاحة حالياً")؛ صفحة المنتج لا تفشل عند `unknown`؛ checkout: 409 ⇒ عرض البنود الناقصة مع الكمية المتاحة وزر "تعديل الكمية"؛ 503 ⇒ رسالة "خدمة المخزون غير متاحة حالياً، لم يُنشأ طلبك، حاول بعد X ثانية" + إعادة المحاولة بنفس Idempotency-Key؛ مؤشر تحميل مع حد زمني للواجهة (يعرض الرسالة حتى لو تأخر الخادم)؛ لوحة المسؤول: حقل `initial_stock` وشاشة تعديل المخزون.
- معايير القبول: اختبارات MSW لكل حالة؛ إعادة المحاولة بعد 503 ترسل **نفس** المفتاح (اختبار).
- **PR:** `feat/r2-web-stock-ux`.

---
## Quality — qa-security-engineer

### P2-QA-01 · إثبات الـ Race Condition عبر HTTP (PROOF-R2-1)
- العضو: B · الفرع: `test/r2-race-chaos` · يعتمد على: دمج `feat/r2-order-saga` و `feat/r2-inventory-service`
- النطاق: `tests/load/race.js`, `tests/security/scripts/race-verify.sh`, هدف `make race-test`.
- الخطوات: التصميم في `docs/06` §3 حرفياً؛ بعد k6: التحقق من المخزون النهائي بـ grpcurl (`available=0`) ومن عدد الطلبات في DB للمنتج (=1) ومن عدد الحجوزات RESERVED (=1)؛ خيار `N=…` لتغيير العدد.
- معايير القبول: `make race-test` يمر 5 مرات متتالية، N=50 و N=200؛ يفشل فعلاً إن عُطّل `FOR UPDATE` مؤقتاً في فرع تجريبي (أثبت أن الاختبار يكشف الخلل ثم أرجع — سجّل النتيجة في وصف الـ PR).

### P2-QA-02 · إثبات توقف/بطء المستودع (PROOF-R2-2)
- العضو: B · الفرع: `test/r2-race-chaos` · يعتمد على: P2-LEAD-02, P2-WEB-01
- الخطوات: Playwright `inventory-down.spec.ts` و `inventory-slow.spec.ts` (يستخدم مساعد Toxiproxy): صفحة المنتج تعرض "غير متاحة حالياً"؛ إنشاء الطلب يعرض رسالة 503 خلال < 2.5s (down) و ≈ 2–3s (latency 3000ms)؛ بعد `reset` ينجح الطلب؛ المخزون بعد سيناريو latency + انتظار المصالحة = المخزون الأصلي (لا تسرّب). + `make demo-r2` (سكربت سردي للمقابلة يشمل `docker compose stop inventory` حرفياً ثم `start`).
- معايير القبول: `make verify-e2e` و `make demo-r2` أخضران.

### P2-QA-03 · مراجعة مستقلة للمرحلة
- الخطوات: `code-reviewer` + `security-checklist` على `main`، مع تركيز: كل مسار شبكي له deadline، كل نتيجة مجهولة لها تعويض، لا قراءة لقاعدة المستودع من التطبيق (grep + فحص الشبكة).
- معايير القبول: لا ملاحظات High/Critical ⇒ **G2**.
