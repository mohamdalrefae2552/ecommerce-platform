# 09 — شرح المشروع كاملاً للفريق (المتطلب 1 + المتطلب 2)

> هذا الملف لكل أعضاء الفريق. اقرأه من الأول للآخر مرة واحدة، ثم ارجع لأي قسم عند الحاجة.
> المحاضر قد يسأل **أي عضو عن أي جزء** — لذلك كل واحد منا يجب أن يفهم الصورة كاملة، لا جزءه فقط.

---

## 1. الفكرة في جملتين
متجر إلكتروني صغير، لكنه مبني **كالأنظمة الحقيقية**: التطبيق الأساسي (PHP/Laravel) يدير المستخدمين والمنتجات والطلبات، وخدمة **منفصلة** للمخزون (Java/Spring Boot) لها قاعدة بياناتها الخاصة، ويتكلمان مع بعض عبر **gRPC**.
التقييم ليس على جمال الواجهة، بل على: **الأمان، صحة البيانات تحت الضغط، والتصرف الصحيح عند الأعطال**.

---

## 2. الصورة الكبيرة (من يكلّم من)

```mermaid
flowchart LR
  B[المتصفح<br/>واجهة React] -->|HTTP :8080| E[edge<br/>nginx]
  E -->|/api → FastCGI| A[api<br/>Laravel / PHP 8.4]
  E -->|/docs| S[swagger<br/>توثيق API]
  A --> DA[(db-app<br/>PostgreSQL)]
  A --> M[mailpit<br/>بريد تجريبي]
  A -->|gRPC| T[toxiproxy<br/>لحقن الأعطال]
  T -->|gRPC :9090| I[inventory<br/>Spring Boot / Java 25]
  I --> DI[(db-inventory<br/>PostgreSQL)]
```

| الخدمة | ما هي | لماذا موجودة |
|---|---|---|
| `edge` | nginx | البوابة الوحيدة المفتوحة للخارج (منفذ 8080): يقدّم الواجهة، يمرّر `/api` للتطبيق، يضيف ترويسات الأمان |
| `api` | Laravel 13 | التطبيق الأساسي: المستخدمون، المنتجات، الطلبات، الإشعارات |
| `scheduler` | نفس صورة api | للمهام الدورية (جاهز للمتطلبات القادمة) |
| `db-app` | PostgreSQL 18 | قاعدة التطبيق الأساسي |
| `inventory` | Spring Boot 4.1 | خدمة المخزون: الكميات، الحجز، الإرجاع، التعديل |
| `db-inventory` | PostgreSQL 18 | قاعدة المخزون — **التطبيق لا يستطيع الوصول إليها أبداً** |
| `toxiproxy` | وسيط | يقع بين api و inventory؛ نستخدمه لنحاكي "المستودع متوقف/بطيء" |
| `swagger` | Swagger UI | توثيق الـ API قابل للتجربة على `/docs` |
| `mailpit` | صندوق بريد وهمي | نرى فيه رسائل الإشعارات على `localhost:8025` |

**العزل (مهم للمقابلة):** يوجد شبكتان في Docker: `app-net` و `inventory-net`. حاوية `api` **ليست** على `inventory-net` أصلاً، فلا تستطيع حتى معرفة عنوان `db-inventory`. هذا يثبت البند R2-S2 فيزيائياً، لا بالاتفاق فقط.

---

## 3. خريطة المجلدات

```
contracts/            ← العقود (أهم مجلد): openapi/openapi.yaml (REST) و proto/ (gRPC) و errors.md
backend/api/          ← Laravel
backend/inventory/    ← Spring Boot
frontend/             ← React
tests/                ← سكربتات الإثبات للمقابلة (demo-r1, demo-r2, race) و k6 و Playwright
infra/                ← إعدادات nginx و toxiproxy وسكربتات المساعدة
docs/                 ← الوثائق والقرارات (ADR)
compose.yaml          ← تعريف كل الحاويات
Makefile              ← كل الأوامر (make up, make demo-r1, ...)
```

**مبدأ "العقد أولاً" (Contract-first):** كتبنا ملف `contracts/openapi/openapi.yaml` (كل endpoint، كل حقل، كل خطأ) و `contracts/proto/inventory/v1/inventory.proto` **قبل** كتابة الكود. الكود يُختبر آلياً أنه يطابق العقد. الواجهة تولّد أنواع TypeScript من العقد، و Laravel يولّد كود gRPC من ملف الـ proto.

---

## 4. التشغيل

| الأمر | ماذا يفعل |
|---|---|
| `make bootstrap` | أول مرة فقط: ينشئ `.env` وكلمات مرور عشوائية، المفاتيح، الجداول، حسابات التجربة |
| `make up` | يشغّل كل الحاويات وينتظر حتى تصبح كلها سليمة |
| `make ps` | يعرض حالة الحاويات |
| `make down` | يوقف كل شيء (البيانات تبقى) |

بعد التشغيل:
- الواجهة: **http://localhost:8080** — وصفحة **دليل العرض**: http://localhost:8080/guide
- توثيق API: http://localhost:8080/docs
- البريد التجريبي: http://localhost:8025

**الحسابات الجاهزة:** المسؤول `admin@example.test` (كلمة المرور في `.env` → `DEV_ADMIN_PASSWORD`)، والزبائن `customer1@example.test` … `customer60@example.test` (كلمة المرور `SEED_CUSTOMER_PASSWORD`). الملف `.env` لا يُرفع إلى GitHub أبداً.

---

## 5. المتطلب 1 — الواجهات البرمجية والأمان

### 5.1 الوحدات الأربع (`backend/api/app/Modules/`)
| الوحدة | ماذا تملك |
|---|---|
| `Users` | التسجيل، الدخول، الأدوار (زبون/مسؤول)، توكنات التحديث |
| `Products` | المنتجات، التصنيفات، الصور، تمرير تعديل المخزون للمستودع |
| `Orders` | إنشاء الطلب، عرضه، آلة الحالات، سجل التغييرات |
| `Notifications` | إشعار + بريد للزبون عند إنشاء الطلب أو تغيير حالته |

بالإضافة إلى `app/Shared/` (الأشياء المشتركة: الأخطاء، الترويسات، JWT، بوابة المخزون).

**قاعدة الحدود:** وحدة لا تستورد Models وحدة أخرى ولا تقرأ جداولها. مثلاً `Orders` تعرف المنتج عبر الواجهة `Products/Contracts/ProductCatalog.php` فقط، والإشعارات تسمع **أحداثاً** (`Orders/Contracts/Events/OrderStatusChanged.php`). هذا **مفروض باختبار آلي** (`backend/api/tests/Arch/`): لو كسر أحدنا القاعدة يفشل الاختبار.

### 5.2 شكل الـ API
- كل المسارات تحت `/api/v1/...` (الإصدار في المسار).
- رموز حالة صحيحة: `201` إنشاء، `200` نجاح، `401` غير مسجل دخول، `403` ممنوع، `404` غير موجود، `409` تعارض، `422` مدخلات خاطئة، `429` محاولات كثيرة، `503` المستودع غير متاح.
- **كل الأخطاء بشكل واحد** (معيار RFC 9457 `application/problem+json`):
  ```json
  {"type":"…","title":"Not found","status":404,"detail":"المورد المطلوب غير موجود.","code":"NOT_FOUND","trace_id":"…"}
  ```
  `code` ثابت تفهمه الواجهة، و `trace_id` يربط الخطأ بسطر في السجلات. الكود: `app/Shared/Http/Problems/`.

### 5.3 المصادقة بـ JWT
- عند الدخول يعطي الخادم **access token** (JWT صالح 15 دقيقة، موقّع بخوارزمية EdDSA) و **refresh token** في كوكي `HttpOnly`.
- الواجهة تحفظ الـ access في **الذاكرة فقط** (لا localStorage — حماية من سرقة XSS).
- عند انتهاء الـ access يُطلب واحد جديد بالـ refresh، والـ refresh **يتبدل** في كل مرة؛ إن استُخدم refresh قديم ⇒ نعتبره سرقة ونلغي كل العائلة.
- التوكن المزوّر، المنتهي، أو بخوارزمية `none` ⇒ `401`.
- الكود: `app/Shared/Auth/` (`JwtGuard`, `TokenIssuer`, `TokenVerifier`) و `app/Modules/Users/Auth/RefreshTokenService.php`.

### 5.4 تحديد المحاولات (Rate limiting)
- الدخول: 5 محاولات/دقيقة لكل (بريد+IP) و 20/دقيقة لكل IP ⇒ المحاولة السادسة `429` مع `Retry-After`.
- التسجيل: 5 حسابات/ساعة لكل IP. إنشاء الطلبات: حد لكل مستخدم.
- الكود: `app/Modules/Users/Providers/UsersServiceProvider.php` و `Orders/Providers/OrdersServiceProvider.php`.

### 5.5 ترويسات الأمان
`Content-Security-Policy` (ضد XSS)، `X-Frame-Options: DENY` (ضد clickjacking)، `X-Content-Type-Options: nosniff`، `Referrer-Policy`، `Permissions-Policy`. على الواجهة من nginx (`infra/nginx/`) وعلى الـ API من `app/Shared/Http/Middleware/SecurityHeaders.php`.

### 5.6 الصلاحيات (أهم نقطة في المتطلب 1)
الملف: `app/Modules/Orders/Policies/OrderPolicy.php`

```php
public function view(AuthUser $user, Order $order): Response
{
    return $user->isAdmin() || $order->user_id === $user->userId()
        ? Response::allow()
        : Response::denyAsNotFound();   // ← 404 وليس 403
}

public function changeStatus(AuthUser $user): Response
{
    return $user->isAdmin() ? Response::allow() : Response::deny();   // ← 403
}
```

- **زبون يفتح طلب زبون آخر ⇒ 404** (لا 403): لأن 403 تعني "الطلب موجود لكنه ليس لك" — وهذا يكشف معلومة. 404 لا يكشف شيئاً (توصية OWASP لثغرة BOLA/IDOR).
- قائمة "طلباتي" مقيّدة في SQL نفسه بـ `where('user_id', …)` — لا نجلب طلبات الآخرين ثم نخفيها.
- **زبون يغيّر الحالة ⇒ 403** (حتى على طلبه هو): تغيير الحالة للمسؤول فقط، والفحص يحدث قبل قراءة البيانات.
- الاختبار الشامل: `tests/Feature/Security/AuthorizationMatrixTest.php` — يجرب **كل endpoint** مع (زائر، مالك، زبون آخر، مسؤول)، ويفشل لو أضاف أحدنا endpoint دون أن يضعه في المصفوفة.

### 5.7 آلة حالات الطلب
`pending → confirmed → shipped → delivered`، ومن `pending` أو `confirmed` ⇒ `cancelled`. أي انتقال آخر ⇒ `409`. كل تغيير يُسجَّل في سجل الحالات مع دور من غيّرها. الكود: `Orders/Domain/OrderStatus.php` و `Orders/Actions/ChangeOrderStatus.php`.

---

## 6. المتطلب 2 — التواصل بين الخدمات (gRPC)

### 6.1 العقد: `contracts/proto/inventory/v1/inventory.proto`
| العملية | معناها |
|---|---|
| `GetStock` | كم بقي من هذه المنتجات؟ |
| `ReserveStock` | احجز كميات **كل** منتجات الطلب — كلها أو لا شيء |
| `ReleaseStock` | أرجِع ما حُجز (عند فشل الطلب) |
| `AdjustStock` | المسؤول يزيد/ينقص الكمية |

من هذا الملف نفسه: Spring يولّد كود الخادم، و Laravel يولّد كود العميل (`backend/api/generated/grpc`).

### 6.2 قاعدة بيانات المخزون (`backend/inventory/src/main/resources/db/migration/V1__init.sql`)
```sql
CREATE TABLE stock_items (
  product_id ...,
  available  bigint NOT NULL CHECK (available >= 0),   -- ← لا يمكن أن تصبح سالبة أبداً
  ...
);
CREATE TABLE reservations (... status IN ('RESERVED','RELEASED'));
CREATE TABLE reservation_lines (...);
CREATE TABLE stock_adjustments (...);
```
**Laravel لا يحتوي أي جدول كميات.** كل ما يخص المخزون في Spring فقط (قرار الفريق D-01).

### 6.3 تدفق إنشاء الطلب (Saga) — `backend/api/app/Modules/Orders/Actions/PlaceOrder.php`

```mermaid
sequenceDiagram
  participant U as الزبون
  participant L as Laravel
  participant I as المخزون (Spring)
  U->>L: POST /orders (+ Idempotency-Key)
  L->>L: سجل محاولة حجز (قبل أي نداء)
  L->>I: ReserveStock (كل المنتجات بنداء واحد، مهلة 2s)
  alt الكمية كافية
    I-->>L: RESERVED
    L->>L: حفظ الطلب في قاعدته
    alt الحفظ نجح
      L-->>U: 201 تم إنشاء الطلب
    else الحفظ فشل (R2-E1)
      L->>I: ReleaseStock (أرجع المحجوز)
      L-->>U: 500
    end
  else الكمية غير كافية (R2-E3)
    I-->>L: INSUFFICIENT_STOCK + النواقص
    L-->>U: 409 مع قائمة النواقص — لا يُحفظ أي طلب
  else المستودع متوقف/بطيء (R2-E2)
    L->>I: ReleaseStock احتياطاً (لا نعرف هل حجز أم لا)
    L-->>U: 503 رسالة واضحة خلال المهلة
  end
```

النقاط المهمة:
1. **لا يُحفظ الطلب إلا بعد موافقة المستودع** (نص المتطلب).
2. **نداء واحد** يحجز كل المنتجات معاً — إن نقص منتج واحد لا يُحجز شيء.
3. **Idempotency-Key:** لو ضغط الزبون "تأكيد" مرتين (أو انقطع الاتصال وأعاد الإرسال) لا يُنشأ طلبان.

### 6.4 لماذا ينجح طلب واحد فقط على آخر قطعة؟ (PROOF-R2-1)
الملف: `backend/inventory/.../persistence/StockRepository.java`

```sql
SELECT ... FROM stock_items WHERE product_id IN (...)
 ORDER BY product_id
 FOR UPDATE;                                   -- ① يقفل الصفوف
UPDATE stock_items SET available = available - :quantity ...   -- ② يخصم
```
- **بدون قفل** (الطريقة الخاطئة "اقرأ ثم اكتب"): 10 طلبات تقرأ معاً `available = 1`، كلها ترى أن الكمية كافية، كلها تخصم ⇒ بِعنا 10 قطع ولدينا قطعة واحدة!
- **مع `FOR UPDATE`:** أول معاملة تقفل الصف، والتسع الأخرى **تنتظر**. الأولى تخصم وتنهي ⇒ `available = 0`. الثانية تستلم القفل وتقرأ القيمة **الجديدة** (0) ⇒ ترفض بـ "الكمية غير كافية". وهكذا.
- **`ORDER BY product_id`:** طلبان يحجزان نفس المنتجين بترتيب مختلف قد يقفل كل منهما صفاً وينتظر الآخر للأبد (Deadlock). عندما يقفل الجميع **بنفس الترتيب** يستحيل هذا.
- **`CHECK (available >= 0)`:** شبكة أمان أخيرة في قاعدة البيانات نفسها.
- الاختبار: `backend/inventory/src/test/java/.../ReservationConcurrencyIT.java` — 50 خيطاً معاً على قطعة واحدة ⇒ نجاح واحد بالضبط، على PostgreSQL حقيقي (Testcontainers).

### 6.5 المهل (Deadlines) — R2-E2
| النداء | المهلة |
|---|---|
| GetStock (عرض الكمية) | 500ms |
| ReserveStock | 2000ms |
| ReleaseStock | 1000ms |
| الميزانية الكلية لإنشاء طلب | 3000ms |

- كل نداء gRPC له مهلة صريحة ⇒ **المستخدم لا ينتظر أكثر من ~3 ثوانٍ أبداً**.
- Spring يتحقق من المهلة قبل أن يبدأ العمل؛ إن انتهت لا يغيّر شيئاً.
- الكود: `backend/api/app/Shared/Inventory/Grpc/GrpcInventoryGateway.php` و `InventoryConfig.php`.

### 6.6 ماذا يرى المستخدم عند الأعطال (PROOF-R2-2)
| الحالة | ما يحدث |
|---|---|
| المستودع **متوقف** | إنشاء الطلب ⇒ `503 INVENTORY_UNAVAILABLE` "خدمة المخزون غير متاحة حالياً" خلال ~60ms. صفحات المنتجات **تبقى تعمل** والكمية "غير متاحة حالياً" (تدهور لطيف — graceful degradation) |
| المستودع **بطيء** (3 ثوانٍ) | المهلة تقطع ⇒ `503 INVENTORY_TIMEOUT` خلال ~3 ثوانٍ بدل التعليق |
| بعد عودة المستودع | كل شيء يعود، ولا تضيع أي كمية محجوزة |

---

## 7. الواجهة (React) — `frontend/src/`
| الصفحة | المسار | الملفات |
|---|---|---|
| المنتجات + صفحة المنتج | `/products` | `features/catalog/` |
| السلة | `/cart` | `features/cart/` |
| طلباتي / تفاصيل الطلب | `/orders` | `features/orders/` |
| لوحة المسؤول | `/admin` | `features/admin/` |
| دليل العرض | `/guide` | `features/guide/` |
| الدخول/التسجيل | `/login`, `/register` | `features/auth/` |

الواجهة تتكلم مع الـ API عبر `src/api/client.ts` (أنواعه مولّدة من العقد: لو تغيّر العقد يفشل البناء حيث يُستخدم الحقل). رسائل الأخطاء بالعربية في `src/api/problem.ts`.

---

## 8. الاختبارات (كيف نعرف أن كل شيء صحيح)
| المكوّن | الأمر | العدد |
|---|---|---|
| Laravel | `make verify-api` | 267 اختباراً + تحليل ثابت (Larastan level max) + تنسيق |
| Spring | `make verify-inventory` | اختبارات وحدة + تكامل على PostgreSQL حقيقي، بما فيها السباق و Deadlock |
| React | `make verify-web` | 48 اختباراً + فحص الأنواع + البناء |
| النظام كاملاً | `make demo-r1` / `make race-test` / `make demo-r2` | 45 + 3 + 16 فحصاً على النظام الحي |

**TDD:** للمنطق الحرج (الصلاحيات، الحجز، الإرجاع) كتبنا الاختبار أولاً ورأيناه يفشل، ثم كتبنا الكود.

---

## 9. سيناريو العرض في المقابلة (من الواجهة فقط)
افتح **http://localhost:8080/guide** واتبع الخطوات. باختصار:

| # | الإثبات | من الواجهة |
|---|---|---|
| 1 | PROOF-R1-1: زبون يفتح طلب غيره | customer1 ينسخ رقم طلبه ← customer2 يفتح `/orders/<الرقم>` ⇒ "الطلب غير موجود" |
| 2 | PROOF-R1-2: زبون يغيّر الحالة | زر "محاولة تغيير الحالة (للتجربة)" ⇒ "ليس لديك صلاحية" |
| 3 | المسؤول يغيّر الحالة | لوحة المسؤول ← الطلبات ← "نقل إلى: مؤكد" + بريد في Mailpit |
| 4 | Rate limit | كلمة مرور خاطئة 6 مرات ⇒ "حاولت كثيراً، انتظر…" |
| 5 | PROOF-R2-1: السباق | المسؤول: "اجعل المخزون 1" ← الزبون: "شغّل اختبار التزامن" ⇒ نجاح **1** ورفض 9 |
| 6 | الكمية غير كافية | اطلب أكثر من المتوفر ⇒ قائمة النواقص |
| 7 | PROOF-R2-2: المستودع متوقف | Docker Desktop ← `inventory` ← Stop ⇒ "الكمية غير متاحة حالياً" + رسالة 503 ← Start ⇒ يعود كل شيء |

بديل بالطرفية (نفس الإثباتات آلياً): `make demo-r1`، `make race-test`، `make demo-r2`.

---

## 10. ما أُجّل (قله بصراحة إن سُئلت)
- **إلغاء الطلب لا يُرجع المخزون بعد** (P2-API-04).
- **المصالحة الدورية:** لو فشل نداء الإرجاع نفسه، يبقى السجل `RELEASE_PENDING`؛ المهمة الدورية التي تعيد المحاولة (P2-API-05) مصممة في `docs/05` ولم تُبنَ بعد.
- لا يمسّان أياً من الإثباتات المطلوبة في المتطلبين.

---

## 11. أسئلة متوقعة
الأسئلة والأجوبة المختصرة في **`docs/08-interview-prep.md`** (25 سؤالاً). أهمها:
1. لماذا 404 لا 403 عند فتح طلب الغير؟ (§5.6)
2. لماذا ينجح طلب واحد فقط؟ (§6.4 — اشرح لماذا "اقرأ ثم اكتب" يفشل)
3. ماذا لو فشل الحفظ بعد الحجز؟ (§6.3)
4. لماذا لكل نداء مهلة؟ ماذا يرى المستخدم إن توقف المستودع؟ (§6.5–6.6)
5. كيف تضمن أن التطبيق لا يقرأ قاعدة المستودع؟ (§2 — الشبكات المنفصلة)
6. لماذا JWT قصير + refresh يتبدل؟ (§5.3)

**نصيحة:** كل عضو يشغّل السيناريو كاملاً بيده مرة على الأقل قبل المقابلة، ويفتح الملفات المذكورة هنا ويقرأ الدالة المهمة في كل منها.
