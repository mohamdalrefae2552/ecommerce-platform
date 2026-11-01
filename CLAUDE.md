# CLAUDE.md — منصة تجارة إلكترونية موزّعة ولحظية

> هذا الملف يُحمَّل تلقائياً في كل جلسة ولكل عضو في فريق الوكلاء (Teammate).
> اقرأه كاملاً قبل أي عمل. التفاصيل في `docs/` وتُقرأ عند الحاجة فقط.

## 1. ما هو المشروع
مشروع مادة "تطبيقات الإنترنت" (جامعة دمشق 2026). متجر صغير يعمل كالأنظمة الحقيقية.
**التقييم على المتطلبات غير الوظيفية** (الأمان، الأداء، التوسع، المراقبة)، لا على كثرة الوظائف ولا على جمال الواجهة.

- الوظائف الإلزامية الخمس: تسجيل المستخدمين · إضافة المنتجات مع صورها وعرضها · إنشاء الطلبات · إدارة المخزون · متابعة حالة الطلب.
- المتطلب 1 (الآن): تصميم الواجهات البرمجية والأمان.
- المتطلب 2 (الآن): التواصل بين الخدمات — خدمة مخزون مستقلة بلغة مختلفة عبر gRPC.
- المتطلبات 3–6 (لاحقاً، لا تُبنى الآن): التخزين المؤقت · المعالجة غير المتزامنة · التوسع · المراقبة.
  **لكن** لا تتخذ قراراً يصعّبها (انظر `docs/01-architecture.md` §8).

الملخص الكامل للمتطلبات ومعايير الإثبات: @docs/00-project-brief.md

## 2. التقنيات (مثبّتة الإصدارات — لا تغيّرها دون ADR)
| المكوّن | التقنية |
|---|---|
| التطبيق الأساسي `backend/api` | PHP 8.4 · Laravel 13 · Pest 4 · Larastan · Pint · PostgreSQL 18 |
| خدمة المخزون `backend/inventory` | Java 25 · Spring Boot 4.1 (`spring-boot-starter-grpc-server`) · Gradle (Kotlin DSL) · JdbcClient · Flyway · Testcontainers · PostgreSQL 18 |
| الواجهة `frontend` | React 19 · TypeScript (strict) · Vite 8 · TanStack Router + Query · openapi-typescript/openapi-fetch · Zod · Vitest · MSW · Playwright |
| العقود `contracts/` | OpenAPI 3.1 (REST) · Protobuf + buf (gRPC) |
| التشغيل | Docker Compose · nginx (edge) · Toxiproxy (حقن الأعطال) · Mailpit · k6 |

إصدارات الحزم الدقيقة تُثبَّت وتُوثَّق في المهمة `P0-LEAD-02` (ADR-0010).

## 3. خريطة المستودع والملكية (قاعدة صارمة)
```
contracts/            ← Lead فقط  (openapi/openapi.yaml, proto/, errors.md)
docs/  tasks/  CLAUDE.md  compose*.yaml  Makefile  .github/  infra/   ← Lead فقط
backend/api/          ← laravel-engineer
backend/inventory/    ← spring-engineer
frontend/             ← react-engineer
tests/e2e/ tests/load/ tests/security/   ← qa-security-engineer
```
- **لا يعدّل أي وكيل ملفاً خارج نطاقه.** إن احتجت تغييراً خارجه: أرسل رسالة (SendMessage) للمالك أو للـ Lead مع السبب والتعديل المقترح بالضبط.
- **العقد أولاً:** لا يُكتب endpoint أو RPC غير موجود في العقد. تغيير العقد = طلب للـ Lead ← يطبّق skill `contract-first` ← يُعلم الجميع.
- الكود المولَّد (`backend/api/generated/`, `frontend/src/api/schema.d.ts`) لا يُعدّل يدوياً أبداً؛ يُولَّد بـ `make gen`.

## 4. القواعد الذهبية (غير قابلة للتفاوض)
1. **لا تخمين.** أي غموض في المتطلب أو العقد → توقّف واسأل الـ Lead (والـ Lead يسأل الإنسان). لا "حلول مؤقتة" ولا TODO صامت ولا كود demo.
2. **TDD للمنطق الحرج:** الصلاحيات، الحجز، التعويض، الانتقالات بين الحالات — الاختبار يُكتب أولاً ويفشل ثم يُكتب الكود.
3. **كل قرار تقني غير بديهي = ADR** في `docs/adr/` (السياق، القرار، البدائل المرفوضة ولماذا، العواقب).
4. **الحدود بين الوحدات:** وحدة Laravel لا تستورد Models/Tables وحدة أخرى؛ التواصل عبر واجهات `Contracts/` و DTOs فقط. تُفرض باختبارات Pest Arch.
5. **فصل المسؤوليات بين الإطارين (قرار الفريق):** كل ما يخص المخزون — الكميات، الحجز، الإرجاع، تعديل المسؤول للكميات — يُنفَّذ ويُخزَّن في **Spring Boot** فقط. **Laravel** يملك المستخدمين والمنتجات والطلبات والإشعارات، ولا يحتوي أي جدول أو منطق كميات؛ يتواصل مع المخزون عبر **gRPC فقط** من خلال `InventoryGateway`، ولا يقرأ قاعدة بيانات المستودع أبداً.
6. **كل نداء شبكي له Deadline** صريح، وكل فشل له رد واضح للمستخدم (RFC 9457).
7. **الأسرار** لا تُكتب في الكود ولا تُقرأ ملفات `.env` الحقيقية. فقط `.env.example`.
8. **لغة الكود:** المعرّفات والتعليقات ورسائل الـ commit بالإنجليزية. رسائل المستخدم في الواجهة بالعربية.
9. لا تُضِف حزمة جديدة دون ذكر السبب في وصف الـ PR (والحزم الكبيرة تحتاج ADR).

## 5. الأوامر (كل شيء عبر Make — لا تشغّل الأدوات مباشرة إلا للتشخيص)
| الأمر | الوظيفة |
|---|---|
| `make up` / `make down` / `make ps` / `make logs S=api` | تشغيل/إيقاف الحاويات |
| `make gen` | توليد stubs الـ PHP من proto + أنواع TS من OpenAPI |
| `make lint-contracts` | `buf lint` + `buf breaking` + Redocly lint على OpenAPI |
| `make verify-api` / `make verify-inventory` / `make verify-web` | lint + static analysis + tests لكل مكوّن |
| `make verify` | كل ما سبق + اختبارات E2E (بوابة الدمج) |
| `make test-api F=Orders` | تشغيل جزء من اختبارات Laravel |
| `make race-test` | إثبات الـ Race Condition (k6: طلبات متزامنة على آخر قطعة) |
| `make chaos-latency MS=3000` / `make chaos-down` / `make chaos-reset` | حقن بطء/توقف بين التطبيق والمستودع |
| `make demo-r1` / `make demo-r2` | سيناريوهات المقابلة |
| `make wt NAME=api BRANCH=feat/r1-auth-jwt` / `make wt-rm NAME=api` | إنشاء/حذف git worktree لوكيل |
| `make fmt-file FILE=<abs path>` | تنسيق ملف واحد (يستدعيه hook تلقائياً بعد كل تعديل؛ يطبع `FMT_INVALID:` إن كان الملف غير صالح) |

الأوامر تعمل داخل الحاويات؛ لا يلزم PHP/Java/Node على الجهاز (Node مطلوب فقط لـ Claude Code نفسه).

## 6. تعريف "منجز" (Definition of Done) لأي مهمة
- [ ] معايير القبول في ملف المهمة (`tasks/phase-*.md`) كلها محققة **ومثبتة باختبار**.
- [ ] `make verify-<component>` أخضر بلا تحذيرات جديدة (Larastan level max، tsc strict، ErrorProne/Spotless).
- [ ] الاستجابات مطابقة لـ `contracts/openapi/openapi.yaml` (اختبار مطابقة العقد).
- [ ] لا كود ميت ولا TODO ولا `dd()/console.log/System.out`.
- [ ] الوثائق/ADR محدّثة إن تغيّر سلوك أو قرار.
- [ ] Commit بصيغة Conventional Commits على فرع المهمة، والمهمة معلّمة completed في قائمة المهام.

## 7. فريق الوكلاء (Agent Teams)
- **Lead** (الجلسة الرئيسية): المعماري والمنسّق. يملك العقود والوثائق والبنية التحتية، يقسّم المهام، يراجع، يدمج. لا ينفّذ مهام الأعضاء بنفسه.
- **فريق Backend:** `laravel-engineer` + `spring-engineer`.
- **فريق Frontend & Quality:** `react-engineer` + `qa-security-engineer`.
- مراجع مستقل: subagent `code-reviewer` (قراءة فقط) يُستدعى قبل كل PR.
- كل عضو يعمل في **worktree خاص به** (مسار مطلق يعطيه الـ Lead في رسالة الإطلاق) وعلى فرع المهمة. لا يعمل أي وكيل في مجلد غيره.
- بروتوكول التواصل وتفاصيل الـ worktrees: `docs/07-team-and-git-workflow.md`.

## 8. قرارات مفتوحة تحتاج تأكيد الإنسان (لا تتجاوزها)
| # | القرار | الافتراض الحالي |
|---|---|---|
| D-01 | إضافة RPC رابع `AdjustStock` لإدارة المخزون | **محسوم ✅ (تأكيد الإنسان):** كل إدارة المخزون في Spring Boot؛ Laravel يمرّرها عبر gRPC فقط |
| D-02 | رد قراءة طلب زبون آخر: 404 أم 403 | 404 (لا نكشف وجود المورد — OWASP)؛ وتغيير الحالة من زبون = 403 |
| D-03 | التوثيق: OpenAPI مكتوب يدوياً (Contract-first) | نعم، مع اختبار مطابقة تلقائي |

## 9. مراجع (اقرأ عند الحاجة)
- المعمارية والتدفقات: `docs/01-architecture.md`
- عقد REST والأخطاء والصلاحيات: `docs/02-api-contract.md`
- عقد gRPC ودلالاته: `docs/03-grpc-contract.md`
- الأمان: `docs/04-security.md`
- الحجز والتزامن والتعويض: `docs/05-inventory-consistency.md`
- الاختبارات والإثباتات: `docs/06-testing-strategy.md`
- الفريق وGit: `docs/07-team-and-git-workflow.md`
- التحضير للمقابلة: `docs/08-interview-prep.md`
- القرارات: `docs/adr/`
- المهام: `tasks/README.md`
