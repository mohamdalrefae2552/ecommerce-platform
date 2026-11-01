# apps/api — Laravel 13 (المالك: laravel-engineer)

## المسؤولية
المستخدمون · المنتجات (بلا كميات) · الطلبات · الإشعارات · واجهة REST v1 · المصادقة والأمان.
**لا مخزون هنا:** الكميات والحجز والإرجاع وتعديل المخزون كلها في Spring Boot؛ هنا فقط `App\Shared\Inventory\InventoryGateway` (عميل gRPC).

## الأوامر (من جذر المستودع أو الـ worktree)
| الأمر | الوظيفة |
|---|---|
| `make verify-api` | البوابة الكاملة |
| `make test-api F=Orders` | فلترة Pest |
| `make shell-api` | shell داخل الحاوية |
| `docker compose exec api php artisan <cmd>` | Artisan |
| `docker compose exec api composer <cmd>` | Composer (اذكر سبب أي حزمة جديدة في الـ PR) |

## الاتفاقيات
- skill `laravel-module` يُحمّل تلقائياً هنا — اتبعه.
- `declare(strict_types=1)`، `final`، `readonly`، أنواع إرجاع كاملة، Enums بدل نصوص.
- قاعدة الاختبار Postgres حقيقية (`db-app-test`)، لا SQLite.
- المعرّفات ULID، المال `*_minor` integer.
- الوقت: `now()` عبر `Carbon` و `travel()` في الاختبارات؛ لا `time()`.
- لا ملفات في `generated/` يدوياً؛ `make gen`.
