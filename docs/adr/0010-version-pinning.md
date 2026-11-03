# ADR-0010: تثبيت إصدارات الحزم والتحقق منها
**الحالة:** مقبول — 2026-11-01 (P0-LEAD-02)

## السياق
الإصدارات الرئيسية محددة في `CLAUDE.md` §2. الحزم الفرعية يجب التحقق من توافقها مع PHP 8.4 / Laravel 13 / Java 25 / Spring Boot 4.1 / Vite 8 **قبل** الاعتماد عليها. التحقق تمّ في 2026-11-01 من السجلات الرسمية مباشرة (Packagist p2، Maven Central و BOM الخاص بـ Spring Boot 4.1.2، `npm view`، GitHub releases، Docker Hub، PECL، قائمة ISO 4217 من SIX).

## القرار — الإصدارات المثبتة
### PHP — `backend/api`
| الحزمة | الغرض | الإصدار المثبت | تاريخ الإصدار | التوافق | المصدر |
|---|---|---|---|---|---|
| `php` (صورة) | التشغيل | `php:8.4.27-fpm-alpine3.24` | 2026-10-25 | ✅ | hub.docker.com/_/php |
| laravel/framework | الإطار | `^13.39` (يتطلب PHP ^8.3) | 2026-10-27 | ✅ | packagist.org/packages/laravel/framework |
| pestphp/pest | الاختبارات | `^4.7.8` (PHPUnit 12) | 2026-08-03 | ✅ — يتضمن `pest-plugin-arch ^4.0.2` تلقائياً | packagist.org/packages/pestphp/pest |
| pestphp/pest-plugin-laravel | مساعدات Laravel | `^4.1.0` | 2026-02-21 | ✅ Laravel ^13 | packagist.org/packages/pestphp/pest-plugin-laravel |
| larastan/larastan | تحليل ثابت | `^3.13.2` (PHPStan ^2.3) | 2026-10-23 | ✅ Laravel 13 | packagist.org/packages/larastan/larastan |
| laravel/pint | التنسيق | `^1.32.1` | 2026-09-10 | ✅ | packagist.org/packages/laravel/pint |
| lcobucci/jwt | JWT EdDSA | `^5.6.1` (يتطلب ext-sodium + ext-openssl) | 2026-10-10 | ✅ PHP 8.4؛ sodium مضمّن في صور php الرسمية | packagist.org/packages/lcobucci/jwt |
| grpc/grpc | مكتبة gRPC | `^1.85.0` | 2026-10-28 | ✅ | packagist.org/packages/grpc/grpc |
| ext-grpc (PECL) | امتداد C | `1.85.0` | 2026-10-26 | ✅ مدرج لـ 8.4 في المثبّت؛ **البناء بطيء** (يُبنى C core) ⇒ طبقة Docker مستقلة مخزنة | pecl.php.net/package/grpc |
| google/protobuf | Protobuf runtime | `^5.36.2` | 2026-09-17 | ✅ (يطابق protobuf-java 4.36.2 في BOM) | packagist.org/packages/google/protobuf |
| mlocati/docker-php-extension-installer | بناء الامتدادات | `2.13.1` | 2026-10-29 | ✅ | github.com/mlocati/docker-php-extension-installer |
| intervention/image | إعادة ترميز الصور | `^3.11.10` | 2026-10-05 | ✅ (v4 موجود؛ نبقى على 3 كما في الخطة — ما زال مُصاناً) | packagist.org/packages/intervention/image |
| **studio-design/openapi-contract-testing** | `assertMatchesContract()` | `^2.9.0` (opis/json-schema، JSON Schema 2020-12) | 2026-10-30 | ✅ **OpenAPI 3.1 أصيل** | github.com/studio-design/openapi-contract-testing |
| laravel/boost (dev، اختياري) | MCP | `^2.10.4` | 2026-10-29 | ✅ | packagist.org/packages/laravel/boost |

### Java — `backend/inventory`
| الحزمة | الغرض | الإصدار المثبت | تاريخ الإصدار | التوافق | المصدر |
|---|---|---|---|---|---|
| Spring Boot (plugin + BOM) | الإطار | `4.1.2` | 2026-10-22 | ✅ Java 17..27، Gradle 8.14+/9.x | docs.spring.io/spring-boot/system-requirements.html |
| spring-boot-starter-grpc-server | خادم gRPC | BOM (4.1.2؛ grpc-java 1.84.1، spring-grpc 1.1.2) | 2026-10-22 | ✅ | repo1.maven.org/…/spring-boot-starter-grpc-server |
| **spring-boot-starter-grpc-server-test** | اختبار gRPC | BOM | 2026-10-22 | ✅ — الاسم `spring-boot-starter-grpc-test` **غير موجود** في GA | BOM 4.1.2 |
| protobuf-java / protoc | التوليد | BOM: protobuf 4.36.2 (Boot يضبط protoc و protoc-gen-grpc-java تلقائياً) | — | ✅ | docs.spring.io/spring-boot/reference/io/grpc.html |
| com.google.protobuf (Gradle plugin) | التوليد | `0.9.6` (الإصدار في وثائق Boot؛ 0.10.0 غير مُتحقق مع ضبط Boot التلقائي) | 2025-12-17 | ✅ | github.com/google/protobuf-gradle-plugin |
| Gradle | البناء | `9.8.2` (wrapper) | 2026-10-19 | ✅ | services.gradle.org |
| Testcontainers | تكامل | BOM `2.0.5` — الحزمة **`org.testcontainers:testcontainers-postgresql`** (الاسم القديم توقف عند 1.21.4) | 2026-04-20 | ✅ | repo1.maven.org/…/testcontainers-postgresql |
| Flyway + flyway-database-postgresql | الهجرات | BOM `12.4.0` | 2026-04-14 | ✅ — Redgate يدرج PostgreSQL 18 كمُتحقَّق | documentation.red-gate.com/fd/postgresql |
| PostgreSQL JDBC | المشغّل | BOM `42.7.14` | 2026-10-07 | ✅ | Maven Central |
| Spotless (Gradle) | التنسيق | `8.10.4` + google-java-format `1.36.1` | 2026-10-08 | ✅ (1.37.0 يفشل مع Spotless 8.10.4: `NoSuchMethodError Style.valueOf`؛ 1.36.1 هو افتراضي Spotless) | github.com/diffplug/spotless |
| Error Prone + net.ltgt.errorprone | فحص ثابت | `2.51.0` + plugin `5.2.0` | 2026-10-22 / 2026-10-30 | ⚠️ يعمل على JDK ≥ 21؛ لا تصريح رسمي بـ 25 — يُثبت عملياً في P0-INV-01 | github.com/google/error-prone |
| JaCoCo | التغطية | `0.8.16` (`jacoco { toolVersion }` صريحاً) | 2026-10-26 | ✅ Java 25 منذ 0.8.14 | github.com/jacoco/jacoco |
| Eclipse Temurin | التشغيل | `eclipse-temurin:25.0.5_8-jre-noble` (بناء واختبار: `25.0.5_8-jdk-noble`؛ noble لأن Testcontainers/JDK على glibc أبسط، و wget يُثبَّت للـ healthcheck) | 2026-10-29 | ✅ | hub.docker.com/_/eclipse-temurin |

### JavaScript — `frontend`
| الحزمة | الغرض | الإصدار المثبت | تاريخ الإصدار | التوافق | المصدر |
|---|---|---|---|---|---|
| Node (صور) | التشغيل | `node:24.22.2-alpine3.24` (LTS) | 2026-10-27 | ✅ | nodejs.org/dist |
| pnpm | مدير الحزم | `12.16.1` (`packageManager` في package.json) | 2026-10-27 | ✅ | npm |
| react / react-dom | الواجهة | `^19.3.1` (يحقق `^19.2` في الخطة) | 2026-10-14 | ✅ | npm |
| vite / @vitejs/plugin-react | البناء | `^8.3.5` / `^6.1.2` | 2026-10-21 / 2026-10-05 | ✅ | npm |
| **typescript** | الأنواع | **`~5.9.3`** | 2025-09-30 | ✅ — انظر "عواقب" | npm |
| @tanstack/react-router + router-plugin | التوجيه | `^1.170.46` + `^1.168.48` | 2026-10-22 | ✅ Vite 8 | npm |
| @tanstack/react-query | حالة الخادم | `^5.105.1` | 2026-11-01 | ✅ | npm |
| openapi-typescript / openapi-fetch | أنواع وعميل مولّد | `^7.13.0` / `^0.17.0` | 2026-02-11 | ✅ OpenAPI 3.1 (مُثبت: يولّد العقد الحالي) | npm |
| zod | التحقق | `^4.6.7` | 2026-10-12 | ✅ | npm |
| msw | المحاكاة | `^3.0.3` | 2026-10-10 | ✅ | npm |
| vitest | الاختبارات | `^5.0.5` | 2026-10-15 | ✅ Vite 8 | npm |
| @testing-library/react (+ dom) | اختبار المكوّنات | `^16.3.3` (+ `^10.4.2`) | 2026-08-27 | ✅ | npm |
| @playwright/test | E2E | `1.64.1` (+ صورة `mcr.microsoft.com/playwright:v1.64.1-noble`) | 2026-10-14 | ✅ | npm |
| eslint / typescript-eslint | Lint | `^10.14.0` / `^8.72.1` | 2026-10-30 / 2026-10-26 | ✅ | npm |
| prettier | التنسيق | `^3.9.10` | 2026-10-10 | ✅ | npm |

### الأدوات والصور
| الأداة | الإصدار المثبت | تاريخ الإصدار | ملاحظة | المصدر |
|---|---|---|---|---|
| buf | `bufbuild/buf:1.75.0` | 2026-10-22 | مُثبت محلياً: lint/build/format/breaking على العقد | github.com/bufbuild/buf |
| Redocly CLI | `redocly/cli:2.72.1` | 2026-10-29 | مُثبت محلياً: lint العقد بلا أخطاء | npm / Docker Hub |
| nginx | **`nginx:1.30.6-alpine`** (stable) | 2026-10-29 | فرع 1.29 انتهى (آخره 1.29.8) | hub.docker.com/_/nginx |
| PostgreSQL | `postgres:18.6-alpine3.24` | 2026-10-26 | | hub.docker.com/_/postgres |
| Toxiproxy | `ghcr.io/shopify/toxiproxy:2.13.0` | 2026-10-14 | | github.com/Shopify/toxiproxy |
| Mailpit | `axllent/mailpit:v1.33.0` | 2026-10-31 | | github.com/axllent/mailpit |
| Swagger UI | `swaggerapi/swagger-ui:v5.34.1` | 2026-10-30 | يدعم OpenAPI 3.1 منذ 5.x | github.com/swagger-api/swagger-ui |
| k6 | `grafana/k6:2.3.0` | 2026-09-21 | خط v2 (أُزيلت APIs المهملة) | github.com/grafana/k6 |
| grpcurl | `v1.9.3` (آخر صورة رسمية؛ الثنائي 1.9.4) | 2026-08-31 | داخل `toolbox` | github.com/fullstorydev/grpcurl |

### العملة
ISO 4217 (قائمة SIX المنشورة 2026-09-17): **`SYP`، الرقم 760، وحدتان صغريان** (1 ل.س = 100 قرش). إعادة التسمية (حذف صفرين) أبقت الرمز `SYP`. ⇒ `amount_minor` = الليرة × 100.

## البدائل المرفوضة
| البديل | لماذا لا |
|---|---|
| `league/openapi-psr7-validator` (و `osteel/…`، `kirschbaum/…` المبنية عليها) | دعم OpenAPI 3.1 جزئي (issue #163 مفتوحة؛ `type: [x, "null"]` غير مدعوم) — يجبرنا على إضعاف العقد |
| `hotmeteor/spectator` | لا يعلن دعم 3.1 |
| TypeScript 6/7 | `openapi-typescript` (peer `^5`) و `typescript-eslint` (`<6.1`) لا يدعمانها بعد |
| Pest 5 / intervention/image 4 | الخطة مثبتة على Pest 4 و image 3، وكلاهما مُصان ومتوافق؛ الترقية تغيير بلا فائدة للمتطلبات |
| nginx 1.31 (mainline) | stable أنسب لنظام يُعرض للتقييم |
| Flyway 13 خارج BOM | تجاوز إدارة Boot للإصدارات بلا حاجة |

## العواقب
+ كل إصدار له مصدر وتاريخ تحقق. lockfiles تُلتزم (`composer.lock`, `pnpm-lock.yaml`, Gradle version catalog + wrapper). لا `latest` ولا `*`.
+ مكتبة مطابقة العقد تدعم 3.1 أصيلاً ⇒ الحقول القابلة لـ null تبقى دقيقة.
− `studio-design/openapi-contract-testing` حديثة وقليلة الاستخدام: إن ظهر خلل نكتب `assertMatchesContract()` كواجهة رقيقة فوقها كي يمكن استبدالها دون تغيير الاختبارات.
− **تصحيح لـ ADR-0004:** لا يوجد `@GrpcAdvice` في Spring Boot 4.1؛ تحويل الاستثناءات عبر beans من نوع `org.springframework.grpc.server.exception.GrpcExceptionHandler`، والـ interceptors العامة بـ `@GlobalServerInterceptor`. وُثّق هنا لأن الـ ADR المقبول لا يُعدّل.
− إصدارا gRPC مختلفان بين الطرفين (PHP 1.85.0، Java 1.84.1) — متوافقان على مستوى البروتوكول.
− ext-grpc يطيل بناء صورة PHP (دقائق) ⇒ طبقة مستقلة في Dockerfile.
− ErrorProne على JDK 25 غير مصرّح به رسمياً ⇒ يُثبت في `make verify-inventory`؛ إن فشل نوقفه بـ ADR.

## القاعدة
لا `latest` ولا `*`. lockfiles مُلتزمة. Renovate/Dependabot اختياري.
