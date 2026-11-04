# KICKOFF — كيف تشغّل Claude Code على هذا المشروع خطوة بخطوة

> هذا الملف لك (الإنسان). فيه المتطلبات المسبقة، ثم **الأوامر النصية الجاهزة** التي تلصقها في Claude Code لكل مرحلة.
> القاعدة: أنت توافق على الخطة قبل التنفيذ، تراقب الفريق، وتفتح الـ PRs من حسابك.

## 0. المتطلبات المسبقة (مرة واحدة)
| الأداة | ملاحظة |
|---|---|
| Docker Engine/Desktop + Compose v2 | كل اللغات تعمل داخل الحاويات |
| Git ≥ 2.38 و GitHub CLI (`gh auth login`) | |
| Node.js 22.12+ أو 24 LTS | لـ Claude Code نفسه |
| Claude Code ≥ **v2.1.32** (`claude --version`) | Agent Teams تجريبية؛ مفعّلة في `.claude/settings.json` |
| tmux (اختياري) | لعرض كل عضو في pane؛ الافتراضي in-process يعمل في أي طرفية (↑/↓ لاختيار عضو، Enter لفتحه، Ctrl+T لقائمة المهام) |

```bash
# إنشاء المستودع ووضع الحزمة فيه
gh repo create <org>/ecommerce-platform --public --clone   # أو --private مع GitHub Education Pro (لحماية الفرع)
cd ecommerce-platform
unzip ~/Downloads/claude-code-kit.zip -d .               # CLAUDE.md, KICKOFF.md, docs/, tasks/, contracts/, .claude/, .github/, backend/*/CLAUDE.md, frontend/CLAUDE.md
chmod +x .claude/hooks/*.sh
git add -A && git commit -m "chore: add project plan, contracts draft and Claude Code team kit" && git push -u origin main
claude            # من جذر المستودع؛ عند السؤال: Trust this folder ✅ (ضروري لتفعيل .claude/agents و hooks)
```
أول مرة داخل Claude Code: نفّذ `/agents` وتأكد ظهور الخمسة (laravel-engineer, spring-engineer, react-engineer, qa-security-engineer, code-reviewer)، و `/hooks` لتأكيد تحميل الـ hooks الأربعة.

---
## المرحلة 0-أ — العقود (Lead وحده)
اضغط `Shift+Tab` حتى **Plan mode** ثم الصق:
```text
أنت الـ Lead لهذا المشروع. اقرأ CLAUDE.md و docs/00..07 و tasks/README.md و tasks/phase-0-foundation.md كاملة.
نفّذ الدفعة 0-أ فقط (P0-LEAD-02, P0-LEAD-03, P0-LEAD-04) بنفسك، بلا فريق.
- P0-LEAD-02: استخدم subagent بحث (general-purpose) للتحقق من كل إصدار في ADR-0010 من المصادر الرسمية، وأعد لي الجدول قبل الكتابة. أي تعارض أو شك: اسألني.
- لا تكتب أي كود تطبيق.
اعرض الخطة أولاً، ثم انتظر موافقتي.
```
بعد موافقتك وانتهائه:
```text
أنشئ الفرع chore/p0-contracts، اجعل كل الـ commits باسم العضو A، شغّل make lint-contracts (أو buf lint + redocly lint مباشرة إن لم يوجد Makefile بعد)،
ثم استدعِ subagent code-reviewer على الفرع، ثم طبّق skill ship-pr وأعطني أوامر الدفع وفتح الـ PR.
```
→ العضو A يدفع ويفتح PR، العضو B يراجع ويدمج.

---
## المرحلة 0-ب — المنصة والهياكل (أول تشغيل للفريق)
```text
ابدأ الدفعة 0-ب من tasks/phase-0-foundation.md.
1) نفّذ بنفسك P0-LEAD-01 (Makefile مع هدف wt) أولاً على الفرع chore/p0-platform في النسخة الرئيسية، ثم أنشئ worktrees:
   make wt NAME=api BRANCH=chore/p0-api-skeleton       MEMBER_NAME="<A>" MEMBER_EMAIL="<a@…>"
   make wt NAME=inv BRANCH=chore/p0-inventory-skeleton MEMBER_NAME="<B>" MEMBER_EMAIL="<b@…>"
   make wt NAME=web BRANCH=chore/p0-web-skeleton       MEMBER_NAME="<B>" MEMBER_EMAIL="<b@…>"
   make wt NAME=qa  BRANCH=test/p0-harness             MEMBER_NAME="<A>" MEMBER_EMAIL="<a@…>"
2) حوّل مهام الدفعة إلى قائمة المهام المشتركة. في وصف كل مهمة ضع سطراً: "worktree: <المسار المطلق>" (الـ hook يعتمد عليه) + معايير القبول كاملة.
3) أنشئ فريق وكلاء (agent team) بأربعة أعضاء بهذه الأسماء والأنواع بالضبط:
   - laravel  ← agent type laravel-engineer      ← worktree api ← P0-API-01
   - spring   ← agent type spring-engineer       ← worktree inv ← P0-INV-01
   - web      ← agent type react-engineer        ← worktree web ← P0-WEB-01
   - qa       ← agent type qa-security-engineer  ← worktree qa  ← P0-QA-01
   في رسالة إطلاق كل عضو: المسار المطلق لـ worktree، معرّف المهمة، المستندات الواجب قراءتها، وتذكير ببروتوكول الرسائل في docs/07 §3.
4) أنت تنفّذ P0-LEAD-06 و P0-LEAD-07 في النسخة الرئيسية بالتوازي. لا تنفّذ مهام الأعضاء بنفسك؛ انتظرهم.
5) عند انتهاء أي عضو: code-reviewer على فرعه ثم ship-pr، وأبلغني.
```
→ PRs بالترتيب في `docs/07` §6. بعد الدمج كله:
```text
من main: make bootstrap && make up && make verify. تحقق من G0 بنداً بنداً واعرض النتيجة. ثم أوقف الأعضاء (shut down) ونظّف الـ worktrees بـ make wt-rm.
```
ثم نفّذ أمر حماية الفرع من `docs/07` §7 بنفسك.

---
## المرحلة 1 — المتطلب الأول
جلسة جديدة (`claude`)، Plan mode:
```text
أنت الـ Lead. اقرأ tasks/phase-1-api-security.md و docs/02 و docs/04.
اعرض خطة: ترتيب المهام وتبعياتها، توزيعها على الأعضاء، worktree وفرع لكل دفعة، والعضو البشري المالك لكل فرع (حسب docs/07 §6).
لا تبدأ قبل موافقتي.
```
بعد الموافقة:
```text
نفّذ الخطة كفريق وكلاء:
- فريق Backend: laravel (laravel-engineer) يبدأ بفرع feat/r1-auth-jwt (P1-API-01..04).
- فريق Frontend & Quality: web (react-engineer) يبدأ P1-WEB-01..02 على MSW في feat/r1-web؛
  qa (qa-security-engineer) يبدأ P1-QA-01 في test/r1-security-proofs من العقد مباشرة.
- spring غير مطلوب في هذه المرحلة؛ لا تطلقه.
- سطر "worktree:" في وصف كل مهمة. 5–6 مهام كحد أقصى لكل عضو في الدفعة.
- قبل كل PR: code-reviewer ثم ship-pr، وأوقفني لأفتح الـ PR من الحساب الصحيح.
- بعد كل دمج: اطلب من كل عضو نشط git fetch && git rebase origin/main && make gen.
```
الدفعات التالية (بعد دمج auth): `P1-API-05` (فرع B) ثم `P1-API-06..07` ثم `P1-API-08`؛ الواجهة تستبدل MSW تدريجياً؛ QA يشغّل الإثباتات. في النهاية:
```text
نفّذ P1-QA-03 (مراجعة أمنية مستقلة بـ skill security-checklist) ثم تحقق من G1: make verify && make demo-r1 من main. اعرض النتائج.
```

---
## المرحلة 2 — المتطلب الثاني
```text
أنت الـ Lead. اقرأ tasks/phase-2-inter-service.md و docs/03 و docs/05 كاملاً.
نفّذ P2-LEAD-01 (تحديث العقود) أولاً وحدك ← PR ← دمج. ثم اعرض خطة الفريق وانتظر موافقتي.
```
بعد الموافقة:
```text
أطلق الفريق:
- spring (spring-engineer): feat/r2-inventory-service (P2-INV-01..06). اختبارات التزامن أولاً.
- laravel (laravel-engineer): feat/r2-inventory-gateway (P2-API-01..02) بـ FakeInventoryGateway حتى يرسل spring [HANDOFF]، ثم feat/r2-order-saga (P2-API-03..05).
- web (react-engineer): feat/r2-web-stock-ux (P2-WEB-01) على MSW.
- qa (qa-security-engineer): test/r2-race-chaos — يبدأ بمساعدات Toxiproxy (P2-LEAD-02 أنفذه أنا)، ثم P2-QA-01..02 بعد الدمج.
اطلب من laravel و spring أن يتفقا برسائل مباشرة على كل حالة في docs/03 §3 قبل كتابة الكود، ويرسلا لي الملخص.
```
النهاية:
```text
من main: make race-test خمس مرات متتالية، ثم make demo-r2، ثم P2-QA-03. تحقق من G2 واعرض مصفوفة الفشل docs/05 §4 مع اسم الاختبار ونتيجته لكل سطر.
```

---
## المرحلة 3 — التسليم
```text
نفّذ tasks/phase-3-hardening-demo.md: P3-LEAD-01 بفريق مراجعة من ثلاثة أعضاء بعدسات مختلفة (أمان، تزامن وفشل، مطابقة العقد وجودة الاختبارات) يتحدّون نتائج بعضهم، ثم P3-LEAD-02 و P3-LEAD-03.
```
وكل عضو بشري: `/interview-demo all <اسمه>`.

---
## نصائح تشغيلية مهمة
- **راقب ووجّه:** افتح عضواً بـ ↑/↓ ثم Enter وكلّمه مباشرة. إن بدأ الـ Lead ينفّذ بنفسه: "انتظر أعضاء الفريق حتى ينهوا مهامهم".
- **حالة المهام قد تتأخر** (قيد معروف): إن بدت مهمة عالقة تحقق يدوياً وقل للـ Lead أن يحدّثها.
- **`/resume` لا يعيد الأعضاء:** بعد الاستئناف اطلب "أطلق أعضاء جدداً بنفس الأسماء والـ worktrees وأكمل من قائمة المهام".
- **التكلفة:** كل عضو جلسة كاملة؛ لا تطلق أعضاء غير مطلوبين للدفعة (مثل spring في المرحلة 1).
- **بديل عند مشاكل الميزة التجريبية:** ضع `CLAUDE_CODE_EXPERIMENTAL_AGENT_TEAMS` = `"0"` وقل للجلسة "استخدم الـ subagents بنفس التعريفات واحداً تلو الآخر" — كل الملفات تعمل كما هي.
- **أنت المسؤول عن الفهم:** اقرأ كل PR لزميلك فعلاً؛ المقابلة تسأل أي عضو عن أي جزء.
