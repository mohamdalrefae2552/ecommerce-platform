---
name: verify
description: تشغيل التحقق الكامل لمكوّن أو للنظام وتفسير الفشل. استخدمه قبل إغلاق أي مهمة، قبل أي PR، وعند فشل الـ CI.
argument-hint: "[api|inventory|web|e2e|all]"
---

# التحقق

المكوّن: `$ARGUMENTS` (الافتراضي: استنتجه من `git diff --name-only origin/main...HEAD`).

| المكوّن | الأمر | يشمل |
|---|---|---|
| api | `make verify-api` | Pint --test، Larastan max، Pest (Unit+Feature+Arch) مع تغطية ≥ 85%، فحص انحراف المولَّد |
| inventory | `make verify-inventory` | Spotless check، ErrorProne، `./gradlew check` (Testcontainers)، JaCoCo ≥ 85% |
| web | `make verify-web` | ESLint، tsc --noEmit، Vitest مع تغطية، build |
| e2e | `make verify-e2e` | يتطلب `make up` على main: Playwright |
| contracts | `make lint-contracts` | Redocly، buf lint، buf breaking |
| all | `make verify` | كل ما سبق |

## عند الفشل
1. اقرأ **أول** خطأ فقط وأصلحه؛ لا تصلح أعراضاً متتالية بشكل أعمى.
2. لا تعطّل قاعدة lint، ولا تضف baseline، ولا `@phpstan-ignore`، ولا `skip()` لاختبار، ولا تخفض عتبة التغطية. إن رأيت ذلك ضرورياً ⇒ `[QUESTION]` للـ Lead مع السبب.
3. اختبار متقطع (flaky) = عيب حقيقي (غالباً تزامن أو ترتيب)؛ لا يُعاد تشغيله حتى ينجح.
4. أعد التشغيل كاملاً بعد الإصلاح وأرفق ملخص النتيجة.
