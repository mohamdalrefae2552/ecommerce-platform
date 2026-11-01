# ADR-0010: تثبيت إصدارات الحزم والتحقق منها
**الحالة:** مقترح — يكمله الـ Lead في `P0-LEAD-02`

## السياق
الإصدارات الرئيسية محددة في `CLAUDE.md` §2. الحزم الفرعية يجب التحقق من توافقها مع PHP 8.4 / Laravel 13 / Java 25 / Spring Boot 4.1 / Vite 8 **قبل** الاعتماد عليها.

## المطلوب ملؤه (جدول بإصدار مثبت + رابط المصدر + تاريخ التحقق)
| الحزمة | الغرض | الإصدار | توافق مُتحقَّق | المصدر |
|---|---|---|---|---|
| laravel/framework | | ^13.x | | |
| pestphp/pest + pest-plugin-laravel + pest-plugin-arch | | ^4 | | |
| larastan/larastan | | | | |
| lcobucci/jwt | JWT EdDSA | ^5 | يتطلب ext-sodium | |
| grpc/grpc + google/protobuf + ext-grpc (pecl) | عميل gRPC | | **تحقق من بناء ext-grpc على PHP 8.4** | |
| intervention/image | إعادة ترميز الصور | ^3 | | |
| مكتبة مطابقة OpenAPI في الاختبارات | `assertMatchesContract()` | | **اختر بعد فحص: `league/openapi-psr7-validator` مقابل بدائل؛ يجب أن تدعم OpenAPI 3.1** | |
| laravel/boost (dev، اختياري) | MCP لـ Claude Code | | | |
| Spring Boot | | 4.1.x | | |
| spring-boot-starter-grpc-server (+ -test) | | (BOM) | | |
| com.google.protobuf (Gradle plugin) | | 0.9.x | | |
| Testcontainers (postgresql) | | (BOM) | | |
| Flyway (+ flyway-database-postgresql) | | (BOM) | | |
| react / react-dom | | ^19.2 | | |
| vite / @vitejs/plugin-react | | ^8 / ^6 | | |
| @tanstack/react-router / react-query | | | | |
| openapi-typescript / openapi-fetch | | | **تحقق دعم OpenAPI 3.1** | |
| msw / vitest / @playwright/test | | | | |
| buf | lint/breaking | | | |
| Redocly CLI | OpenAPI lint | | | |

## القاعدة
لا `latest` ولا `*`. lockfiles مُلتزمة (`composer.lock`, `pnpm-lock.yaml`, `gradle.lockfile` أو version catalog). Renovate/Dependabot اختياري.
