# 06 — استراتيجية الاختبار والإثباتات

## 1. الهرم لكل مكوّن
| المكوّن | Unit | Integration | Contract | أدوات |
|---|---|---|---|---|
| api (Laravel) | Actions, state machine, JWT, gateway mapping (بـ fake gRPC client) | Feature tests على Postgres حقيقي (`db-app-test`، RefreshDatabase) | كل استجابة ⇐ `assertMatchesContract()` ضد OpenAPI؛ Arch tests للحدود | Pest 4، Larastan (level max)، Pint |
| inventory (Spring) | domain + validator | Testcontainers Postgres 18 + gRPC in-process server؛ اختبارات تزامن حقيقية بخيوط متعددة | توليد من proto نفسه + `buf breaking` | JUnit 5، AssertJ، Testcontainers، Spotless، ErrorProne |
| web (React) | hooks/utils | مكونات + MSW (handlers مطابقة للعقد) | أنواع TS مولّدة من OpenAPI ⇒ أي انحراف = خطأ tsc | Vitest، Testing Library، MSW، ESLint، tsc |
| النظام | — | Playwright E2E على compose كامل | — | Playwright، k6، Toxiproxy |

## 2. بوابات الجودة (CI يفشل دونها)
- Larastan: level `max` بلا baseline جديد.
- تغطية الأسطر: `app/Modules` و `app/Shared` ≥ 85%؛ Spring `application` + `persistence` ≥ 85% (JaCoCo)؛ web ≥ 70%.
- (موصى به للتميّز) اختبار الطفرات: Infection على `Orders/Actions` و `Shared/Inventory` (MSI ≥ 75%)، PIT على `ReservationService` (≥ 80%).
- `buf lint` + `buf breaking --against '.git#branch=origin/main,subdir=contracts/proto'`.
- Redocly lint على OpenAPI (قواعد `recommended` + إلزام `operationId` وأمثلة).
- انحراف المولَّد: `make gen && git diff --exit-code`.

## 3. اختبارات الإثبات (Proof tests) — تُعرض في المقابلة
كل إثبات له **اختبار آلي** + **أمر عرض** يمكن تشغيله أمام الممتحن.

| ID | الاختبار الآلي | أمر العرض |
|---|---|---|
| PROOF-R1-1 | `tests/Feature/Orders/OrderAccessTest.php` › `customer cannot view another customer's order` + Playwright `idor.spec.ts` | `make demo-r1` (الخطوة 1) |
| PROOF-R1-2 | `OrderAccessTest` › `customer cannot change order status` (dataset: صاحب الطلب وغيره) | `make demo-r1` (الخطوة 2) |
| SEC-RATE | `LoginThrottleTest` | `make demo-r1` (الخطوة 3) |
| SEC-JWT | `JwtGuardTest` (tampered, alg none, expired, wrong aud, unknown kid) | `make demo-r1` (الخطوة 4) |
| SEC-HEADERS | `SecurityHeadersTest` | `make demo-r1` (الخطوة 5) |
| PROOF-R2-1 | `ReservationConcurrencyIT` (50 خيطاً × مخزون 1 ⇒ نجاح واحد بالضبط، `available=0`، صف حجز واحد) **و** `tests/load/race.js` عبر HTTP كامل (50 زبوناً مختلفاً، `per-vu-iterations` متزامن، عتبة `checks`: `count(201)==1 && count(409)==49`) + سكربت تحقق يقرأ المخزون النهائي عبر grpcurl | `make race-test` |
| PROOF-R2-2 | `tests/e2e/inventory-down.spec.ts`: إيقاف عبر Toxiproxy ⇒ صفحة المنتج تعرض "الكمية غير متاحة حالياً" والطلب يعرض رسالة 503 عربية خلال < 2.5s | `make demo-r2` (`chaos-down` ثم `chaos-latency MS=3000` ثم `chaos-reset`)، وكذلك `docker compose stop inventory` حرفياً كما في العرض |

### تصميم `race.js` (k6) بدقة
- `setup()`: ينشئ منتجاً جديداً بـ `initial_stock=1` عبر admin، ويسجّل 50 زبوناً ويجمع توكناتهم (خارج زمن القياس).
- السيناريو: `executor: 'per-vu-iterations', vus: 50, iterations: 1` + حاجز بدء (كل VU ينام حتى `startAt` مشترك من setup) ⇒ تزامن فعلي.
- كل VU: `POST /api/v1/orders` بـ Idempotency-Key مختلف.
- `handleSummary()`: يطبع عدد 201/409/أخرى ويفشل (exit ≠ 0) إن لم يكن 201 = 1 بالضبط و 409 = 49 وغيرهما = 0.
- PHP-FPM `pm.max_children` ≥ 16 كي يكون التزامن حقيقياً على مستوى المستودع (وثّقه).

## 4. ما لا نختبره بـ mocks
- قاعدة البيانات في اختبارات التكامل حقيقية دائماً (لا SQLite بدل Postgres — السلوك مختلف في الأقفال).
- التزامن لا يُختبر بـ mocks إطلاقاً.

## 5. أسماء الاختبارات
بصيغة سلوكية: `it('returns 404 when a customer requests another customer\'s order')` — كي تُقرأ كمواصفة في المقابلة.
