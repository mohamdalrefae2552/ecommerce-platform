# المرحلة 0 — الأساس (العقود + المنصة + الهياكل)

> الترتيب: الدفعة 0-أ (Lead وحده) ⇒ PR العقود ⇒ الدفعة 0-ب (الفريق كاملاً بالتوازي) ⇒ PR المنصة ⇒ G0.

---
## الدفعة 0-أ — Lead وحده

### P0-LEAD-01 · هيكل المستودع وملفات الجذر
- المالك: Lead · العضو: B · الفرع: `chore/p0-platform` · يعتمد على: —
- النطاق: `.gitignore`, `.editorconfig`, `.gitattributes`, `README.md`, `Makefile`, `.env.example`, `.github/pull_request_template.md` (موجود في الحزمة)، `.github/CODEOWNERS` (اختياري).
- الخطوات:
  1. `.gitignore` يغطي: `.env`, `**/vendor`, `**/node_modules`, `**/build`, `**/.gradle`, `backend/api/storage/keys/*`, `*.log`, ملفات IDE.
  2. `Makefile` بكل الأهداف المذكورة في `README.md` (قسم الأوامر) (تنفيذاً حقيقياً لا placeholders؛ الأهداف التي تعتمد على مكونات غير جاهزة بعد تطبع رسالة واضحة وتخرج بـ 1).
     - `PROJECT ?= $(notdir $(CURDIR))` ⇒ `COMPOSE := docker compose -p $(PROJECT)` لدعم worktrees متعددة.
     - `make wt NAME BRANCH MEMBER_NAME MEMBER_EMAIL` و `make wt-rm NAME` كما في `infra/scripts/wt.sh`.
     - `make bootstrap`: يولّد `APP_KEY` ومفاتيح JWT ويشغّل الهجرات والبذور.
     - `make fmt-file FILE=<abs>`: حسب الامتداد والمسار — PHP ⇒ Pint داخل حاوية `api`، Java ⇒ `spotlessApply` لملف واحد (أو تخطٍّ بـ exit 0 إن كان بطيئاً ووثّق)، TS/JSON/CSS/MD ⇒ Prettier، proto ⇒ `buf format -w`؛ **عقد المخرج**: نجاح = تم/لا شيء؛ فشل مع سطر يبدأ بـ `FMT_INVALID:` = الملف نفسه غير صالح؛ أي فشل آخر = عطل بنية (تنبيه غير مانع). (لا نعتمد رمز الخروج لأن make يخرج دائماً بـ 2 عند فشل أي recipe.) الحاوية غير مشغلة ⇒ نجاح بصمت.
     - `make verify-*` و `make lint-contracts` يجب أن تعمل من أي worktree.
  3. README: المتطلبات المسبقة، التشغيل بثلاثة أوامر (`cp .env.example .env && make bootstrap && make up`)، الروابط (app, docs, mailpit)، حسابات العرض.
- معايير القبول: `make help` يسرد كل الأهداف بوصف؛ `make wt NAME=x BRANCH=chore/x` ينشئ worktree بمؤلف مضبوط و `COMPOSE_PROJECT_NAME` و `EDGE_PORT` فريدين، و `make wt-rm NAME=x` يزيله نظيفاً.

### P0-LEAD-02 · التحقق من الإصدارات وتثبيتها (ADR-0010)
- المالك: Lead · العضو: A · الفرع: `chore/p0-contracts` · يعتمد على: —
- الخطوات: لكل حزمة في جدول ADR-0010: ابحث في المصدر الرسمي (Packagist/Maven Central/npm/GitHub releases) عن آخر إصدار مستقر متوافق؛ تحقق صراحةً من: `ext-grpc` على PHP 8.4، مكتبة مطابقة OpenAPI 3.1 لـ PHP، دعم `openapi-typescript` لـ 3.1، دعم Java 25 في Spring Boot 4.1 و Gradle.
- معايير القبول: ADR-0010 مكتمل بالحالة "مقبول"، كل سطر فيه إصدار + مصدر + تاريخ. أي حزمة غير متوافقة ⇒ بديل مبرر يقرّه الفريق. **لا يبدأ أي عضو قبل إغلاق هذه المهمة.**

### P0-LEAD-03 · عقد gRPC نهائي
- المالك: Lead · العضو: A · الفرع: `chore/p0-contracts` · يعتمد على: —
- النطاق: `contracts/proto/**` (المسودة موجودة وتجتاز `buf lint`).
- الخطوات: راجع المسودة مقابل `docs/03` و `docs/05` (D-01 محسوم: AdjustStock ضمن العقد)؛ أضف `buf.gen.yaml` إن لزم؛ هدف `make lint-contracts`.
- معايير القبول: `buf lint` و `buf build` ناجحان؛ كل رسالة/حقل موثق بتعليق.

### P0-LEAD-04 · عقد OpenAPI 3.1 كامل
- المالك: Lead · العضو: A · الفرع: `chore/p0-contracts` · يعتمد على: P0-LEAD-02
- النطاق: `contracts/openapi/openapi.yaml` (+ تقسيم اختياري في `paths/`, `components/` مع `$ref`)، `contracts/openapi/redocly.yaml`، `contracts/errors.md`.
- الخطوات: حوّل `docs/02` حرفياً إلى OpenAPI: كل مسار، كل معامل بحدوده (`minimum/maximum/maxItems/pattern`)، `securitySchemes.bearerAuth` (JWT)، كوكي refresh، كل الردود الممكنة لكل عملية (بما فيها 401/403/404/422/429/503) عبر `components/responses` تشير لـ `Problem` (+ `ValidationProblem`, `InsufficientStockProblem`, `RateLimitedProblem`)، أمثلة واقعية لكل طلب/رد، `operationId` بصيغة camelCase، tags بأسماء الوحدات.
- معايير القبول: `redocly lint` بلا أخطاء ولا تحذيرات؛ كل endpoint في `docs/02` §4 الخاص بالمتطلب 1 موجود (قائمة مطابقة في وصف الـ PR)؛ يعرض في Swagger UI دون أخطاء.
- **مؤجّل إلى P2-LEAD-01 (إضافة غير كاسرة، phase-1 P1-API-05):** `initial_stock`، `PUT /products/{id}/stock`، ردود 409 `INSUFFICIENT_STOCK` و 503 على `POST /orders`، `InsufficientStockProblem`، `meta.degraded`. الرموز نفسها موجودة في `ProblemCode` منذ الآن.

### P0-LEAD-05 · PR العقود
- المالك: Lead · العضو: A (يفتح) / B (يراجع) · الفرع: `chore/p0-contracts` · يعتمد على: P0-LEAD-02..04
- معايير القبول: مراجعة مستقلة بلا ملاحظات حرجة؛ PR مدموج في `main`. **هذه نقطة التزامن: الفريق يبدأ من هنا.**

---
## الدفعة 0-ب — الفريق بالتوازي (كل عضو في worktree)

### P0-LEAD-06 · Docker Compose + nginx + أدوات
- المالك: Lead · العضو: B · الفرع: `chore/p0-platform` · يعتمد على: P0-LEAD-05
- النطاق: `compose.yaml`, `compose.dev.yaml`, `infra/nginx/**`, `infra/toolbox/Dockerfile`, `infra/toxiproxy/toxiproxy.json`.
- الخطوات: كل الخدمات في `docs/01` §2 بالشبكتين المعزولتين؛ healthchecks حقيقية لكل خدمة و `depends_on: condition: service_healthy`؛ `restart: unless-stopped`؛ volumes مسماة؛ لا منفذ منشور إلا `edge` (`${EDGE_PORT:-8080}`) و mailpit UI في dev؛ nginx: `/api` → php-fpm، `/storage` → volume الصور، `/docs` → swagger، `/` → SPA مع fallback لـ `index.html`، ترويسات أمان الواجهة (`docs/04` §4)، `server_tokens off`، `client_max_body_size 3m`، توليد `X-Request-Id` إن غاب؛ صفحات خطأ nginx لمسارات `/api` (413، 502، 504) تُعاد كـ `application/problem+json` بنفس صيغة العقد عبر `error_page` + `location` داخلي (لا HTML أبداً على `/api`)؛ Toxiproxy يُهيأ عند الإقلاع بـ proxy `inventory`.
- معايير القبول: `make up` ⇒ `make ps` يعرض الكل healthy خلال ≤ 120s على جهاز نظيف (بعد البناء)؛ `docker compose exec api getent hosts db-inventory` يفشل (العزل)؛ `curl -sI localhost:8080` يعرض ترويسات الأمان.

### P0-LEAD-07 · CI (GitHub Actions)
- المالك: Lead · العضو: B · الفرع: `chore/p0-platform` · يعتمد على: P0-LEAD-06
- النطاق: `.github/workflows/ci.yml`.
- الخطوات: jobs بأسماء ثابتة `contracts`, `api`, `inventory`, `web`, `e2e` مع `paths` filters (لكن `e2e` على كل PR إلى main)؛ caching (composer, pnpm, gradle, docker layers)؛ `concurrency` لإلغاء التشغيلات القديمة؛ رفع تقارير الاختبار والتغطية كـ artifacts؛ `permissions: contents: read`.
- معايير القبول: الـ jobs تعمل على PR تجريبي؛ أسماؤها مطابقة لأمر حماية الفرع في `docs/07` §7؛ تطبيق حماية الفرع فعلياً (الإنسان ينفذ الأمر).

### P0-API-01 · هيكل Laravel 13
- المالك: API (Laravel) · العضو: A · الفرع: `chore/p0-api-skeleton` · يعتمد على: P0-LEAD-05
- النطاق: `backend/api/**`.
- الخطوات:
  1. Laravel 13 API-only (`install:api` بلا Sanctum)، PHP 8.4.
  2. `Dockerfile` متعدد المراحل: `php:8.4-fpm` + `install-php-extensions grpc pdo_pgsql sodium opcache intl gd` (أو imagick)، مستخدم غير root، `expose_php=Off`، opcache للإنتاج، `pm.max_children` قابل للضبط بمتغير (افتراضي 16)؛ مرحلة `test`.
  3. هيكل `app/Modules/{Users,Products,Orders,Notifications}` و `app/Shared/{Http,Auth,Inventory}` كما في `docs/01` §3، Service Provider لكل وحدة يسجّل routes/migrations/policies.
  4. أدوات: Pest 4 + plugins، Larastan level max، Pint (preset laravel + `declare_strict_types`)، `composer scripts`: `lint`, `analyse`, `test`.
  5. `tests/Arch/ModuleBoundariesTest.php` بالقواعد في `docs/01` §3 (تمر الآن على الهيكل الفارغ وتفشل إن خُرقت — أضف fixture تجريبي في اختبار يثبت أنها تلتقط الخرق ثم احذفه).
  6. `assertMatchesContract()` helper (بالمكتبة من ADR-0010) + `RoutesMatchContractTest`.
  7. `GET /api/v1/health`.
- معايير القبول: `make verify-api` أخضر؛ `/api/v1/health` 200 ويطابق العقد؛ Larastan max بلا أخطاء؛ صورة الإنتاج بلا dev deps.

### P0-INV-01 · هيكل Spring Boot 4.1
- المالك: INV (Spring) · العضو: B · الفرع: `chore/p0-inventory-skeleton` · يعتمد على: P0-LEAD-05
- النطاق: `backend/inventory/**`.
- الخطوات: Java 25 toolchain، Gradle Kotlin DSL + version catalog، `spring-boot-starter-grpc-server` + `spring-boot-starter-grpc-server-test`، JDBC + Flyway + Postgres driver، Actuator (منفذ إدارة داخلي)، protobuf plugin يقرأ `../../contracts/proto`، Spotless (google-java-format)، ErrorProne، JaCoCo بعتبة 85% (مفعّلة لاحقاً حين يوجد كود)، Testcontainers؛ تنفيذ `InventoryService` يعيد `UNIMPLEMENTED` مؤقتاً **فقط في هذه المهمة**؛ gRPC health + reflection؛ graceful shutdown؛ JSON logging + `RequestIdServerInterceptor`؛ Dockerfile متعدد المراحل (JRE 25، غير root، layered jar).
- معايير القبول: `make verify-inventory` أخضر؛ `grpcurl -plaintext inventory:9090 list` من `toolbox` يعرض `inventory.v1.InventoryService` و health؛ اختبار يتحقق أن الخادم يقلع مع Testcontainers.

### P0-WEB-01 · هيكل React
- المالك: WEB (React) · العضو: B · الفرع: `chore/p0-web-skeleton` · يعتمد على: P0-LEAD-05
- النطاق: `frontend/**`.
- الخطوات: Vite 8 + React 19 + TS strict (`noUncheckedIndexedAccess`, `exactOptionalPropertyTypes`)، pnpm، ESLint (flat config + typescript-eslint strict + react-hooks)، Prettier، TanStack Router (file-based) + Query، `openapi-typescript` ⇒ `src/api/schema.d.ts` (عبر `make gen`)، `src/api/client.ts` (openapi-fetch + middleware: Authorization، `X-Request-Id`، تحويل الأخطاء إلى `ProblemError` typed)، MSW handlers أساسية، `dir="rtl"` و `lang="ar"`، Vitest + Testing Library، Playwright مثبت (الاختبارات عند QA)، Dockerfile لبناء static يُنسخ إلى `edge`.
- معايير القبول: `make verify-web` أخضر (lint + tsc + vitest + build)؛ تغيير حقل في العقد ثم `make gen` ⇒ tsc يفشل حيث يُستخدم (اختبره ثم أرجعه).

### P0-QA-01 · هيكل الاختبارات الشاملة
- المالك: QA · العضو: A · الفرع: `test/p0-harness` · يعتمد على: P0-LEAD-05
- النطاق: `tests/e2e/**` (Playwright + TS)، `tests/load/**` (k6)، `tests/security/http/**` (ملفات `.http`)، `tests/security/scripts/**`.
- الخطوات: Playwright config يستهدف `EDGE_URL`؛ fixtures لإنشاء مستخدمين عبر API؛ مساعد Toxiproxy (REST API الخاص به) لـ `down/latency/reset`؛ k6 skeleton + مكتبة مشتركة؛ `make verify-e2e`.
- معايير القبول: اختبار smoke يفتح الصفحة الرئيسية و `/api/v1/health` ويمر على `make up`؛ مساعد Toxiproxy مختبر (latency تقاس فعلاً).

### P0-LEAD-08 · مراجعة ودمج المنصة + G0
- المالك: Lead · يعتمد على: كل ما سبق
- الخطوات: مراجعة مستقلة لكل فرع؛ PRs بالترتيب: platform ⇒ api/inventory/web skeletons ⇒ harness؛ بعد الدمج من `main`: `make bootstrap && make up && make verify`.
- معايير القبول: **G0** محققة (انظر `tasks/README.md`).
