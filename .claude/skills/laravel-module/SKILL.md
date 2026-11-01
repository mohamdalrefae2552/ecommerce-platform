---
name: laravel-module
description: اتفاقيات كتابة الكود داخل وحدات Laravel (Users, Products, Orders, Notifications) — البنية، Actions، Policies، Resources، الاختبارات، والحدود بين الوحدات. يُستخدم عند إنشاء أو تعديل أي شيء في apps/api.
paths:
  - "apps/api/**"
---

# العمل داخل وحدة Laravel

## مكان كل شيء
| الشيء | المسار |
|---|---|
| Route | `app/Modules/<M>/routes.php` (يُحمّل تحت `/api/v1` من Provider الوحدة) |
| Controller (رفيع) | `Http/Controllers/<Name>Controller.php` — أسلوب واحد = endpoint واحد، أو invokable |
| تحقق | `Http/Requests/<Action>Request.php` (`authorize()` يعيد true؛ التفويض في Policy) |
| تفويض | `Policies/<Model>Policy.php` مسجل في Provider الوحدة |
| حالة استخدام | `Actions/<Verb><Noun>.php` — `final readonly class` بأسلوب `handle()` |
| رد | `Http/Resources/<Model>Resource.php` — قائمة حقول صريحة |
| واجهة عامة للوحدات الأخرى | `Contracts/*.php` + `Contracts/DTO/*` + `Contracts/Events/*` |
| هجرات/مصانع | `Database/Migrations`, `Database/Factories` |
| اختبارات | `tests/Feature/<M>/`, `tests/Unit/<M>/` |

## قواعد
- لا `use App\Modules\<Other>\Models|Actions|Database\...` — فقط `Contracts`. (اختبار Arch سيفشل.)
- لا FK لجداول وحدة أخرى؛ ULID + لقطة.
- كل ما يكتب أكثر من جدول ⇒ `DB::transaction` داخل الـ Action. الأحداث `afterCommit`.
- استثناءات النطاق تنفذ `DomainProblem` (status + code من الكتالوج) — لا `abort()` بنصوص حرة.
- لا منطق مخزون في Laravel: استخدم `InventoryGateway` فقط.

## قالب اختبار Feature
```php
it('returns 404 when a customer requests another customer\'s order', function () {
    [$owner, $intruder] = User::factory()->customer()->count(2)->create();
    $order = Order::factory()->for($owner)->create();

    $this->withToken(jwtFor($intruder))
        ->getJson("/api/v1/orders/{$order->id}")
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'NOT_FOUND')
        ->assertMatchesContract();
});
```
## قبل إغلاق المهمة
`make verify-api` (Pint، Larastan max، Pest مع التغطية، Arch).
