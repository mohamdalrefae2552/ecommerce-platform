# 05 — الحجز، التزامن، والتعويض (قلب المتطلب 2)

## 1. لماذا تفشل الطريقة الساذجة (Race Condition)
```
T1: SELECT available → 1      T2: SELECT available → 1
T1: 1 ≥ 1 ✓ UPDATE → 0        T2: 1 ≥ 1 ✓ UPDATE → -1   ← بيعت القطعة مرتين
```
"اقرأ ثم تحقق ثم اكتب" (check-then-act) غير آمنة بين معاملتين متزامنتين في `READ COMMITTED`. المطلوب في PROOF-R2-1: من N طلب متزامن على آخر قطعة **ينجح واحد فقط**.

## 2. الحل المعتمد (ADR-0005): أقفال صفوف تشاؤمية بترتيب حتمي + قيد CHECK
### المخطط (Flyway `V1__init.sql`)
```sql
CREATE TABLE stock_items (
  product_id  varchar(26) PRIMARY KEY,
  available   bigint      NOT NULL CHECK (available >= 0),   -- خط الدفاع الأخير
  updated_at  timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE reservations (
  id          uuid        PRIMARY KEY,                       -- reservation_id من العميل
  status      varchar(16) NOT NULL CHECK (status IN ('RESERVED','RELEASED')),
  created_at  timestamptz NOT NULL DEFAULT now(),
  released_at timestamptz,
  release_reason varchar(48)
);
CREATE TABLE reservation_lines (
  reservation_id uuid        NOT NULL REFERENCES reservations(id),
  product_id     varchar(26) NOT NULL REFERENCES stock_items(product_id),
  quantity       int         NOT NULL CHECK (quantity > 0),
  PRIMARY KEY (reservation_id, product_id)
);
CREATE TABLE stock_adjustments (
  id uuid PRIMARY KEY, product_id varchar(26) NOT NULL REFERENCES stock_items(product_id),
  delta bigint NOT NULL CHECK (delta <> 0), note varchar(200), created_at timestamptz NOT NULL DEFAULT now()
);
```

### خوارزمية `ReserveStock` (معاملة واحدة، `READ COMMITTED`)
1. تحقق من المدخلات (لا تكرار، حدود)، وتحقق من الـ deadline.
2. `INSERT INTO reservations(id,status) VALUES (:id,'RESERVED') ON CONFLICT (id) DO NOTHING`
   - إن **لم** يُدرج (موجود مسبقاً): اقرأ حالته ⇒ `RESERVED` ⇒ أعد `RESERVED` (إعادة آمنة، لا حجز مزدوج). `RELEASED` ⇒ أعد `RESERVATION_ALREADY_RELEASED`. **انتهى.**
   - ملاحظة: Postgres يجعل `ON CONFLICT` ينتظر معاملة متزامنة لم تُثبَّت بعد على نفس المفتاح ⇒ لا سباق بين Reserve و Release على نفس المعرّف.
3. `SELECT product_id, available FROM stock_items WHERE product_id = ANY(:ids) ORDER BY product_id FOR UPDATE`
   - **الترتيب حسب `product_id`** ⇒ كل المعاملات تأخذ الأقفال بنفس الترتيب ⇒ **لا Deadlock** حتى لو طلبان بمنتجات متعاكسة الترتيب.
   - منتج غير موجود في الجدول ⇒ يعامل كـ `available = 0`.
4. احسب كل النواقص (لا تتوقف عند أول نقص — الواجهة تعرض كل البنود الناقصة).
5. إن وُجد نقص ⇒ `ROLLBACK` كامل (يمحو صف reservations أيضاً) ⇒ أعد `INSUFFICIENT_STOCK` + `shortages`.
6. وإلا ⇒ `UPDATE stock_items SET available = available - :q, updated_at = now() WHERE product_id = :id` لكل بند + `INSERT reservation_lines` ⇒ `COMMIT` ⇒ `RESERVED`.

**لماذا ينجح واحد فقط:** المعاملة الأولى تمسك قفل الصف؛ البقية تنتظر عند `FOR UPDATE`؛ بعد الـ commit تقرأ القيمة الجديدة (0) — في `READ COMMITTED` يعيد Postgres تقييم الصف المقفل بعد تحرره — فتجد نقصاً وترجع. وقيد `CHECK (available >= 0)` يجعل أي خطأ برمجي مستقبلي يفشل بصوت عالٍ بدل بيع سالب.

### `ReleaseStock`
1. `INSERT INTO reservations(id,status,released_at,release_reason) VALUES (:id,'RELEASED',now(),:r) ON CONFLICT (id) DO NOTHING`
   - أُدرج ⇒ لم يكن موجوداً ⇒ **tombstone** ⇒ `NOT_FOUND_TOMBSTONED`.
2. وإلا `SELECT status FROM reservations WHERE id=:id FOR UPDATE`:
   - `RELEASED` ⇒ `ALREADY_RELEASED`.
   - `RESERVED` ⇒ أقفل صفوف `stock_items` للبنود **بترتيب product_id**، أعد الكميات، `status='RELEASED'` ⇒ `RELEASED`.

### `AdjustStock`
معاملة واحدة بالترتيب:
1. `INSERT INTO stock_items(product_id, available) VALUES (:pid, 0) ON CONFLICT (product_id) DO NOTHING` — منتج يُرى لأول مرة يحصل على صف (وإلا يفشل FK في الخطوة 2).
2. `INSERT INTO stock_adjustments … ON CONFLICT (id) DO NOTHING` (idempotency). لم يُدرج ⇒ تعديل مكرر ⇒ أعد الكمية الحالية **دون** تطبيق delta (والطلب المكرر بحمولة مختلفة يُتجاهل — من يضمن تطابق الحمولة هو Laravel عبر `Idempotency-Key`).
3. `UPDATE stock_items SET available = available + :delta … WHERE product_id = :pid RETURNING available` — النتيجة السالبة تكسر CHECK ⇒ `ROLLBACK` كامل (يمحو الصف الجديد والتعديل) ⇒ `FAILED_PRECONDITION`. يشمل ذلك delta سالباً لمنتج جديد.

### البدائل التي رُفضت (اذكرها في المقابلة)
| البديل | لماذا لا |
|---|---|
| تحقق في Laravel ثم نداء | التطبيق لا يملك البيانات؛ وcheck-then-act عبر الشبكة أسوأ |
| `UPDATE … WHERE available >= :q` لكل بند (شرطي) | صحيح لبند واحد، لكن لعدة بنود يحتاج rollback يدوي ولا يجمع كل النواقص؛ ونحتاج الأقفال المرتبة على أي حال |
| Optimistic locking (`version`) + إعادة محاولة | تحت تنافس عالٍ على آخر قطعة (سيناريو الاختبار نفسه) ⇒ عواصف إعادة محاولة |
| `SERIALIZABLE` | صحيح لكنه يُفشل المعاملات بـ serialization failure ويحتاج retry loop؛ أصعب في الإثبات والشرح |
| Advisory locks / Redis lock | تعقيد إضافي ونقطة فشل جديدة بلا فائدة هنا |

## 3. الـ Saga في Laravel (`Orders\Actions\CreateOrder`) — R2-F1, R2-E1..E3
### جدول اليوميات (journal) في `db-app`
```
inventory_reservation_attempts(
  id ulid PK, reservation_id uuid UNIQUE, user_id, idempotency_key uuid NULL,
  state  enum(PENDING, CONFIRMED, REJECTED, RELEASED, RELEASE_PENDING),
  order_id ulid NULL, last_error varchar NULL, created_at, updated_at)
```
### الخطوات
1. **Idempotency:** `Cache::lock("orders:idem:{user}:{key}", 10)` ثم: إن وُجد `orders(user_id, idempotency_key)` ⇒ قارن hash الجسم ⇒ أعد الطلب (200) أو 422 `IDEMPOTENCY_KEY_REUSED`. قيد `UNIQUE(user_id, idempotency_key)` على `orders` كشبكة أمان.
2. تحقق المنتجات عبر `ProductCatalog::findMany()` (موجودة، فعّالة) وخذ لقطة السعر.
3. `reservation_id = Str::uuid()` **جديد لكل محاولة** (ليس مشتقاً من Idempotency-Key — كي يمكن إعادة المحاولة بنفس المفتاح بعد فشل).
4. أدرج attempt بحالة `PENDING` و**ثبّته** (commit) قبل النداء ⇒ لو مات الـ PHP-FPM بعد الحجز، المصالحة تجده.
5. `ReserveStock` بـ deadline 2s.
6. حسب النتيجة:
| النتيجة | الإجراء | الرد |
|---|---|---|
| RESERVED | `DB::transaction`: insert `orders` + `order_items` + `order_status_history(pending)` + attempt=`CONFIRMED`,`order_id` | 201 |
| ↳ فشل المعاملة (أي استثناء) | `ReleaseStock(ORDER_PERSISTENCE_FAILED)` ⇒ attempt=`RELEASED` (أو `RELEASE_PENDING` إن فشل الإفراج) | 500 `INTERNAL_ERROR` |
| INSUFFICIENT_STOCK | attempt=`REJECTED` | 409 + shortages |
| Timeout / Unavailable / Aborted | `ReleaseStock(RESERVE_OUTCOME_UNKNOWN)` best-effort ⇒ `RELEASED` أو `RELEASE_PENDING` | 503 |
7. `OrderPlaced` event بعد الـ commit ⇒ إشعار.

### إلغاء الطلب (admin → `cancelled`)
داخل معاملة تغيير الحالة: سجّل الانتقال، وبعد الـ commit نادِ `ReleaseStock(ORDER_CANCELLED)` بمعرّف الحجز المرتبط؛ عند الفشل ⇒ attempt=`RELEASE_PENDING` (المصالحة تكمل). الإلغاء لا يُرفض بسبب توقف المستودع.

### المصالحة (`php artisan inventory:reconcile` كل دقيقة في `scheduler`)
- attempts بحالة `PENDING` أقدم من 2 دقيقة **وبلا order** ⇒ `ReleaseStock(RECONCILIATION)` ⇒ `RELEASED`.
- attempts بحالة `RELEASE_PENDING` ⇒ إعادة `ReleaseStock` ⇒ `RELEASED`.
- `withoutOverlapping()`؛ دفعات 100؛ log لكل إجراء. آمنة لأن Release idempotent.

## 4. مصفوفة الفشل الكاملة (كل سطر = اختبار)
| # | السيناريو | المخزون النهائي | رد المستخدم | الاختبار |
|---|---|---|---|---|
| 1 | N=50 طلباً متزامناً، المخزون 1 | 0، حجز واحد | 1×201، 49×409 | `ReservationConcurrencyIT` (Spring) + `tests/load/race.js` (E2E) |
| 2 | طلبان متزامنان بمنتجين بترتيب معاكس (A,B) و (B,A) | صحيح، بلا deadlock | كلاهما 201 إن كفى المخزون | `DeadlockFreedomIT` |
| 3 | Reserve مكرر بنفس المعرّف | حجز واحد | — | `ReserveIdempotencyIT` |
| 4 | Release قبل Reserve بنفس المعرّف (Reserve بطيء) | لا حجز | Reserve ⇒ ALREADY_RELEASED | `ReleaseBeforeReserveIT` |
| 5 | Release مكرر | لا إعادة مزدوجة | — | `ReleaseIdempotencyIT` |
| 6 | فشل حفظ الطلب بعد الحجز | الكمية أُعيدت | 500 | `CreateOrderCompensationTest` (Laravel، DB fault injection) |
| 7 | المستودع متوقف | لا تغيير | 503 `INVENTORY_UNAVAILABLE` خلال < 2.5s | `tests/e2e/inventory-down.spec.ts` + chaos |
| 8 | المستودع بطيء (latency 3s) | لا حجز يتيم بعد المصالحة | 503 `INVENTORY_TIMEOUT` خلال ≈ 2s | `chaos-latency` + `ReconcilerTest` |
| 9 | الكمية غير كافية لبند من ثلاثة | لا تغيير لأي بند | 409 + البند الناقص | `ReserveAllOrNothingIT` |
| 10 | صفحة منتج والمستودع متوقف | — | 200 و `stock.status=unknown` | `ProductShowDegradedTest` |
| 11 | إعادة POST بنفس Idempotency-Key | طلب واحد | 201 ثم 200 | `IdempotencyTest` |
| 12 | الإلغاء والمستودع متوقف | يُعاد عند عودة المستودع | 200 للإلغاء | `CancelWhileInventoryDownTest` |
