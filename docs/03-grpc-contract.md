# 03 — عقد gRPC بين التطبيق وخدمة المخزون

العقد: `contracts/proto/inventory/v1/inventory.proto` (package `inventory.v1`). يجتاز `buf lint` (STANDARD) ويخضع لـ `buf breaking` ضد `main` في CI.

## 1. توليد الكود
| الطرف | الآلية | المخرج |
|---|---|---|
| Spring (خادم) | Gradle `com.google.protobuf` plugin، `sourceSets.main.proto.srcDir("../../contracts/proto")` — Spring Boot 4.1 يضبط `protoc` وإصداره تلقائياً | `build/generated/…` (غير مُلتزَم) |
| Laravel (عميل) | `make gen` ← حاوية `protoc` (`protoc` + `grpc_php_plugin`) | `apps/api/generated/grpc/` (مُلتزَم + فحص انحراف `git diff --exit-code` في CI) |
autoload: `"Ecommerce\\Inventory\\V1\\": "generated/grpc/Ecommerce/Inventory/V1/"` و `GPBMetadata` بالمثل.

## 2. المهل (Deadlines) — R2-E2
| RPC | Deadline (العميل) | المتغير | إعادة المحاولة |
|---|---|---|---|
| GetStock | 500ms | `INVENTORY_DEADLINE_GET_MS` | لا (نُرجع `unknown`) |
| ReserveStock | 2000ms | `INVENTORY_DEADLINE_RESERVE_MS` | مرة واحدة فقط عند `UNAVAILABLE` وبشرط بقاء ≥ 1000ms من ميزانية الطلب الكلية 3000ms؛ آمنة لأن العملية Idempotent بنفس `reservation_id` |
| ReleaseStock | 1000ms | `INVENTORY_DEADLINE_RELEASE_MS` | عند الفشل: الصف يبقى `RELEASE_PENDING` وتعالجه المصالحة |
| AdjustStock | 2000ms | `INVENTORY_DEADLINE_ADJUST_MS` | لا (المسؤول يعيد بنفس Idempotency-Key) |
- PHP: الخيار `['timeout' => $ms * 1000]` (ميكروثانية) في كل نداء، **ولا نداء بدونه** (يُفرض في `GrpcInventoryGateway` وحده).
- Spring: الخادم يتحقق من `Context.current().getDeadline()` قبل بدء الـ transaction ويرفض بـ `DEADLINE_EXCEEDED` إن انتهى؛ و `statement_timeout`/`lock_timeout` في Postgres أقل من أطول مهلة (مثال: `lock_timeout=1500ms`).

## 3. خريطة الأخطاء
### في الخادم (Spring — `@GrpcAdvice`)
| الحالة | gRPC status |
|---|---|
| مدخل غير صالح (ULID خاطئ، كمية خارج 1..1000، بنود مكررة، قائمة فارغة/أكثر من 100، reservation_id ليس UUID) | `INVALID_ARGUMENT` + رسالة تصف الحقل |
| AdjustStock يجعل الكمية سالبة | `FAILED_PRECONDITION` |
| انتهاء المهلة/إلغاء العميل | `DEADLINE_EXCEEDED` / `CANCELLED` |
| `lock_timeout` في DB | `ABORTED` (العميل يعامله كـ UNAVAILABLE) |
| أي استثناء آخر | `INTERNAL` (بلا تفاصيل داخلية في الرسالة، التفاصيل في الـ log مع request-id) |
| نقص الكمية | **ليس خطأ**: `OK` + `RESERVE_OUTCOME_INSUFFICIENT_STOCK` |

### في العميل (Laravel — `GrpcInventoryGateway` يحوّل إلى استثناءات نطاق)
| gRPC | استثناء PHP | HTTP للمستخدم |
|---|---|---|
| OK + RESERVED | — | يكمل |
| OK + INSUFFICIENT_STOCK | `InsufficientStock(shortages)` | 409 `INSUFFICIENT_STOCK` |
| OK + RESERVATION_ALREADY_RELEASED | `ReservationAborted` | 503 `INVENTORY_TIMEOUT` (لا يحدث إلا بعد مهلة سابقة) |
| `DEADLINE_EXCEEDED` | `InventoryTimeout` | 503 `INVENTORY_TIMEOUT` + `Retry-After: 5` |
| `UNAVAILABLE`, `ABORTED`, `RESOURCE_EXHAUSTED` | `InventoryUnavailable` | 503 `INVENTORY_UNAVAILABLE` + `Retry-After: 5` |
| `INVALID_ARGUMENT`, `INTERNAL`, غيرها | `InventoryContractViolation` (يُسجَّل error) | 500 `INTERNAL_ERROR` |
**قاعدة:** أي نتيجة غير مؤكدة لـ ReserveStock (Timeout/Unavailable/Aborted) ⇒ تعويض فوري `ReleaseStock(RESERVE_OUTCOME_UNKNOWN)`.

## 4. التحقق من الاستجابات (OWASP API10 — عدم الثقة العمياء)
العميل يتحقق: `reservation_id` المعاد يساوي المرسل؛ `levels` بنفس العدد والترتيب؛ `available ≥ 0`؛ `outcome ≠ UNSPECIFIED`. أي خرق ⇒ `InventoryContractViolation`.

## 5. Metadata
- `x-request-id`: من الطلب HTTP، إلزامي. الخادم يضعه في MDC لكل log.
- (المتطلب 5/6 لاحقاً: `traceparent` W3C.)

## 6. الأمان على مستوى النقل
داخل شبكة Docker معزولة: قناة `insecure` مقبولة الآن ومُوثّقة كدَين تقني في ADR-0004 (الترقية لـ mTLS في متطلب التوسع). لا منفذ gRPC منشور على الجهاز.

## 7. أدوات العرض
```bash
docker compose exec toolbox grpcurl -plaintext inventory:9090 list
docker compose exec toolbox grpcurl -plaintext -d '{"product_ids":["01J…"]}' inventory:9090 inventory.v1.InventoryService/GetStock
```
(`toolbox` = حاوية profile `tools` فيها grpcurl و psql و jq.)
