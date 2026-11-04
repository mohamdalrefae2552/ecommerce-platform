# منصة تجارة إلكترونية موزّعة ولحظية

مشروع مادة "تطبيقات الإنترنت" — جامعة دمشق 2026. متجر صغير يعمل كالأنظمة الحقيقية، ويُقيَّم على **الأمان والتزامن والصمود أمام الأعطال**.

| الجزء | التقنية | المجلد |
|---|---|---|
| التطبيق الأساسي (المستخدمون، المنتجات، الطلبات، الإشعارات) | PHP 8.4 · Laravel 13 · PostgreSQL 18 | `backend/api` |
| خدمة المخزون (gRPC) | Java 25 · Spring Boot 4.1 · PostgreSQL 18 | `backend/inventory` |
| الواجهة | React 19 · TypeScript · Vite 8 | `frontend` |
| العقود | OpenAPI 3.1 · Protobuf | `contracts` |

المعمارية: [`docs/01-architecture.md`](docs/01-architecture.md) · القرارات: [`docs/adr/`](docs/adr/) · المهام: [`tasks/README.md`](tasks/README.md)

## المتطلبات المسبقة
| الأداة | ملاحظة |
|---|---|
| Docker Desktop (أو Engine) + Compose v2 | كل اللغات تعمل داخل الحاويات؛ على Windows يلزم WSL2 |
| GNU make + bash + git | على Windows: Git Bash و `choco install make` |
| curl + jq | لسكربتات العرض فقط (`make demo-r1/r2`) |

لا يلزم PHP أو Java أو Node على الجهاز.

## التشغيل من الصفر (ثلاثة أوامر)
```bash
cp .env.example .env
make bootstrap
make up
```
`make bootstrap` يولّد `APP_KEY` ومفاتيح JWT (لا تُلتزم أبداً)، ويشغّل الهجرات والبذور. `make up` ينتظر حتى تصبح كل الخدمات healthy.

## الروابط
| الخدمة | الرابط |
|---|---|
| المتجر (الواجهة) | http://localhost:8080 |
| الـ API | http://localhost:8080/api/v1 |
| توثيق الـ API (Swagger — Try it out) | http://localhost:8080/docs |
| البريد (Mailpit، التطوير فقط) | http://localhost:8025 |

قواعد البيانات و gRPC **غير منشورة** على الجهاز؛ `edge` (nginx) هو المنفذ الوحيد.

> **ملاحظة HTTP المحلي:** كوكي الـ refresh يحمل `Secure`. المتصفحات تقبله على `http://localhost` لأنها تعامله كسياق آمن — استخدم `localhost` لا عنوان IP.

## حسابات العرض
تُنشأ بالبذور في `make bootstrap` (تُضاف في المرحلة 1). المسؤول يُنشأ فقط بالأمر:
```bash
docker compose exec api php artisan app:create-admin admin@example.com
```

## الأوامر
```bash
make help
```
| الأمر | الوظيفة |
|---|---|
| `make verify` | بوابة الدمج: العقود + كل المكوّنات + E2E |
| `make verify-api` / `verify-inventory` / `verify-web` | تحقق مكوّن واحد |
| `make gen` | توليد stubs الـ PHP من proto وأنواع TS من OpenAPI |
| `make demo-r1` / `make demo-r2` | سيناريوهات المقابلة |
| `make race-test` | إثبات الـ Race Condition (طلبات متزامنة على آخر قطعة) |
| `make chaos-down` / `chaos-latency MS=3000` / `chaos-reset` | إيقاف/إبطاء المستودع أمام الممتحن |
