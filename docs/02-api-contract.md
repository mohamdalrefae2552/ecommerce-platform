# 02 — عقد REST (المصدر لكتابة `contracts/openapi/openapi.yaml`)

> هذا المستند هو **المواصفة**؛ الملف `openapi.yaml` (OpenAPI 3.1) هو **العقد التنفيذي** الذي يكتبه الـ Lead في `P0-LEAD-04` ويُختبر عليه الكود تلقائياً.
> عند أي تعارض بينهما: توقف واسأل الـ Lead.

## 1. قواعد عامة (R1-A1..A4)
- **الإصدار في المسار:** كل المسارات تحت `/api/v1`. إصدار جديد = مسار جديد، لا كسر لـ v1.
- **JSON فقط:** الطلبات `application/json` (عدا رفع الصورة `multipart/form-data`). أي `Accept` آخر لا يغيّر الصيغة. Middleware `ForceJson`.
- **أسماء الحقول:** `snake_case`. التواريخ ISO-8601 UTC (`2026-11-01T17:41:00Z`). المعرّفات ULID نصية.
- **المال:** `{ "amount_minor": 450000000, "currency": "SYP" }` — عدد صحيح بالوحدة الصغرى (1 ل.س = 100 قرش، ISO 4217) — لا أعداد عشرية للمال أبداً. العملة الوحيدة: **الليرة السورية `SYP`** (قرار الإنسان في P0).
- **الترقيم (R1-A6):** `?page=1&per_page=20` (`per_page` من 1 إلى 100، الافتراضي 20). القيم خارج النطاق ⇒ 422 (لا قصّ صامت).
- **غلاف القوائم:**
  ```json
  { "data": [ … ],
    "meta": { "page": 1, "per_page": 20, "total": 57, "last_page": 3 },
    "links": { "first": "…", "prev": null, "next": "…?page=2", "last": "…?page=3" } }
  ```
- **غلاف المورد الواحد:** `{ "data": { … } }`.
- **إنشاء ناجح:** `201` + ترويسة `Location` + المورد في الجسم.
- **حذف/خروج ناجح:** `204` بلا جسم.
- **Idempotency:** `POST /orders` يقبل `Idempotency-Key` (UUID). نفس المفتاح + نفس الجسم ⇒ نفس الطلب (201 أول مرة، 200 بعدها). نفس المفتاح + جسم مختلف ⇒ 422 `IDEMPOTENCY_KEY_REUSED`.
- **Request-Id:** كل استجابة تحمل `X-Request-Id`.

## 2. صيغة الأخطاء الموحدة — RFC 9457 Problem Details (R1-A3)
`Content-Type: application/problem+json` لكل خطأ دون استثناء (بما فيها 404 لمسار غير موجود و 405 و 500).
```json
{
  "type": "https://ecommerce.local/problems/insufficient-stock",
  "title": "Insufficient stock",
  "status": 409,
  "detail": "الكمية المتوفرة غير كافية لبعض المنتجات.",
  "instance": "/api/v1/orders",
  "code": "INSUFFICIENT_STOCK",
  "trace_id": "01JC…",
  "errors": { },
  "shortages": [ { "product_id": "01J…", "requested": 2, "available": 1 } ]
}
```
- `code` ثابت وقابل للاعتماد عليه برمجياً (الواجهة تترجمه لرسالة عربية).
- `errors` (لـ 422 فقط): `{ "field": ["message", …] }`.
- **لا** stack traces ولا رسائل SQL ولا مسارات ملفات في الإنتاج (`APP_DEBUG=false`)، حتى في 500.
- حقول إضافية خاصة بالخطأ مسموحة (`shortages`, `retry_after`).

### كتالوج الأخطاء (يُنسخ إلى `contracts/errors.md`)
| HTTP | code | متى |
|---|---|---|
| 400 | `MALFORMED_REQUEST` | JSON غير صالح، ترويسة Idempotency-Key ليست UUID |
| 401 | `UNAUTHENTICATED` | لا توكن / توكن غير صالح (توقيع، iss، aud، alg) — مع `WWW-Authenticate: Bearer` |
| 401 | `TOKEN_EXPIRED` | توكن منتهي (يدفع الواجهة لـ refresh) |
| 401 | `INVALID_CREDENTIALS` | بريد أو كلمة مرور خاطئة (رسالة واحدة للحالتين) |
| 401 | `REFRESH_TOKEN_INVALID` | refresh مفقود/منتهي/ملغى/أعيد استخدامه |
| 403 | `FORBIDDEN` | الدور لا يسمح بالعملية (مثال: زبون يغيّر حالة طلب) |
| 404 | `NOT_FOUND` | مورد غير موجود **أو** ليس للمستخدم حق رؤيته (D-02) |
| 405 | `METHOD_NOT_ALLOWED` | |
| 409 | `INSUFFICIENT_STOCK` | المستودع رفض الحجز (R2-E3)، أو خصم مخزون أكبر من المتاح في `PUT /products/{id}/stock` + `shortages` |
| 409 | `ORDER_INVALID_TRANSITION` | انتقال حالة غير مسموح (مثال: delivered → pending) |
| 413 | `PAYLOAD_TOO_LARGE` | جسم الطلب > 3MB (يرد به `edge`) |
| 415 | `UNSUPPORTED_MEDIA_TYPE` | |
| 422 | `VALIDATION_FAILED` | + `errors` (يشمل: صورة > 2MB ⇒ خطأ على `image`، حقل غير معروف في الجسم، ترقيم خارج النطاق) |
| 422 | `IDEMPOTENCY_KEY_REUSED` | |
| 429 | `RATE_LIMITED` | + `Retry-After` + `retry_after` |
| 500 | `INTERNAL_ERROR` | |
| 503 | `INVENTORY_UNAVAILABLE` | المستودع متوقف (gRPC UNAVAILABLE) + `Retry-After` |
| 503 | `INVENTORY_TIMEOUT` | تجاوز المهلة (DEADLINE_EXCEEDED) + `Retry-After` |

## 3. الأدوار ومصفوفة الصلاحيات (R1-SEC4, R1-SEC5)
الأدوار: `customer` (افتراضي عند التسجيل)، `admin` (يُنشأ فقط بأمر `php artisan app:create-admin`، **لا يُقبل `role` من أي طلب**).

| Endpoint | زائر | customer | admin |
|---|---|---|---|
| `POST /auth/register` · `POST /auth/login` · `POST /auth/refresh` | ✅ | ✅ | ✅ |
| `POST /auth/logout` · `GET /auth/me` | 401 | ✅ | ✅ |
| `GET /categories` · `GET /products` · `GET /products/{id}` | ✅ | ✅ | ✅ |
| `POST /products` · `PATCH /products/{id}` · `PUT /products/{id}/stock` | 401 | 403 | ✅ |
| `POST /orders` | 401 | ✅ | 403 (المسؤول لا يشتري — قرار تبسيط) |
| `GET /orders` | 401 | طلباته فقط | كل الطلبات (+ فلتر `status`, `user_id`) |
| `GET /orders/{id}` | 401 | طلبه ✅ · طلب غيره **404** | ✅ |
| `PATCH /orders/{id}/status` | 401 | **403** | ✅ |
| `GET /notifications` · `PATCH /notifications/{id}/read` | 401 | إشعاراته فقط (غيرها 404) | إشعاراته فقط |

التطبيق: Policies في كل وحدة + `Gate::before` **غير مستخدم** (لا تجاوز شامل للمسؤول؛ كل سياسة صريحة). استعلامات القوائم مقيدة على مستوى SQL (`where user_id = auth id`) لا تصفية بعد الجلب.

## 4. المسارات
### Auth (وحدة Users)
| Method | Path | Body | Success |
|---|---|---|---|
| POST | `/auth/register` | `{name, email, password, password_confirmation}` | 201 `{data: user}` |
| POST | `/auth/login` | `{email, password}` | 200 `{data:{access_token, token_type:"Bearer", expires_in:900, user}}` + Set-Cookie refresh |
| POST | `/auth/refresh` | — (كوكي `refresh_token`) + ترويسة `X-Requested-With: fetch` | 200 مثل login + كوكي جديد (تدوير) |
| POST | `/auth/logout` | — | 204 + حذف الكوكي + إلغاء عائلة التوكن |
| GET | `/auth/me` | — | 200 `{data: user}` |

قواعد التحقق: `email` RFC + lowercase + فريد (≤ 254)؛ `password` 12..128 حرفاً (الحد الأعلى يمنع استنزاف argon2 — API4) + `Password::defaults()->uncompromised()` (اختياري إن لم يتوفر إنترنت في الحاوية — يُقرَّر في P1)؛ `name` 2..100.

### Products
| Method | Path | ملاحظات | Success |
|---|---|---|---|
| GET | `/categories` | قائمة ثابتة مزروعة (seed) | 200 |
| GET | `/products?category={slug}&page&per_page&q` | `q` بحث بالاسم (اختياري، 1..100 حرف)؛ `page` 1..100000 | 200 قائمة؛ كل عنصر فيه `stock` (انظر أدناه) |
| GET | `/products/{id}` | يسأل المستودع (R2-F2) | 200 |
| POST | `/products` | multipart: `name` (2..120)، `description` (اختياري ≤ 2000)، `price_minor` (1..100,000,000,000)، `category_id`، `image` (+ `initial_stock` ابتداءً من المتطلب 2) | 201 |
| PATCH | `/products/{id}` | **(المتطلب 3)** JSON جزئي: `name`، `description`، `price_minor`، `category_id` (حقل واحد على الأقل؛ الصورة والمخزون لا يُعدّلان هنا). يُبطل كاش الكتالوج بعد الـ commit فيظهر التعديل في القراءة التالية (ADR-0013) | 200 المنتج |
| PUT | `/products/{id}/stock` | **(المتطلب 2)** `{delta, note?}` (`delta` ≠ 0، \|delta\| ≤ 1,000,000، `note` ≤ 200) + `Idempotency-Key` **إلزامي** ⇒ `AdjustStock`. خصم أكبر من المتاح ⇒ 409 `INSUFFICIENT_STOCK` (+ `shortages`)؛ المستودع متوقف ⇒ 503 | 200 `{data:{product_id, available}}` |

**تمثيل المخزون في المنتج (graceful degradation — PROOF-R2-2):**
```json
"stock": { "status": "in_stock" | "out_of_stock" | "unknown", "available": 3 | null }
```
`unknown` + `available: null` عندما يكون المستودع متوقفاً/بطيئاً (مهلة 500ms). صفحة المنتج تبقى 200 ولا تفشل.

**الصورة:** `jpeg|png|webp`، ≤ 2MB، أبعاد ≤ 4000×4000. يُعاد ترميزها إلى WebP (تجريد EXIF وإبطال الملفات متعددة الصيغ polyglot)، اسم عشوائي ULID، تُخدم من `edge` على `/storage/products/{ulid}.webp` مع `X-Content-Type-Options: nosniff`. الحقل `image_url` مطلق.

### Orders
| Method | Path | Body | Success |
|---|---|---|---|
| POST | `/orders` | `{items:[{product_id, quantity}]}` (1..20 بنداً، quantity 1..100، بلا تكرار) + `Idempotency-Key` | 201 |
| GET | `/orders?status&page&per_page` | | 200 — ملخص لكل طلب (`id, user_id, status, total, item_count, created_at, updated_at`) بلا بنود ولا سجل |
| GET | `/orders/{id}` | | 200 مع `items`, `total`, `status`, `status_history` (كل عنصر: `from, to, actor_role, note, at` — معرّف المنفّذ يُخزَّن ولا يُعرض، API3) |
| PATCH | `/orders/{id}/status` | `{status, note?}` (`note` ≤ 500) | 200 |

**آلة الحالات (Orders\Domain\OrderStatus):**
```
pending ──► confirmed ──► shipped ──► delivered
   │            │
   └────────────┴──► cancelled   (يطلق ReleaseStock في المتطلب 2)
```
أي انتقال آخر ⇒ 409 `ORDER_INVALID_TRANSITION`. التغيير داخل transaction مع `lockForUpdate` على صف الطلب. كل تغيير يُسجَّل في `order_status_history (from, to, actor_id, note, at)`.

### Notifications
| Method | Path | Success |
|---|---|---|
| GET | `/notifications?unread=true&page` | 200 |
| PATCH | `/notifications/{id}/read` | 200 |
الإشعارات المولّدة: `order.placed` (للزبون)، `order.status_changed` (للزبون). القنوات: database + mail (Mailpit).

### Health
`GET /api/v1/health` ⇒ 200 `{status:"ok", checks:{db:"ok", inventory:"ok|degraded"}}` — بلا معلومات حساسة. إن لم تُجب قاعدة البيانات ⇒ 500 problem+json.

## 5. التوثيق القابل للتجربة (R1-A4)
- `swagger` على `http://localhost:8080/docs` يقرأ `openapi.yaml` مع **Try it out** يعمل (نفس الأصل ⇒ لا CORS).
- كل عملية في العقد لها: `operationId`، أمثلة طلب/استجابة، كل الأخطاء الممكنة مرجعةً إلى `components/responses/*` التي تشير إلى schema `Problem`.
- مجموعة طلبات جاهزة للعرض: `tests/security/http/*.http` (مستخدمة في demo).
- **اختبار مطابقة العقد:** كل اختبار Feature في Laravel يتحقق تلقائياً أن الاستجابة تطابق `openapi.yaml` (helper `assertMatchesContract()`؛ المكتبة تُحدَّد في ADR-0010).
