# 07 — الفريق وسير العمل في Git

## 1. الفريق
- **عضوان (A و B):** يملكان القرار، يكتبان الكود، يراجعان، يفتحان طلبات الدمج من حساباتهما، ويُسألان في المقابلة عن أي جزء (W-3).

## 2. المكوّنات والمجلدات
| المكوّن | المجلد | ملاحظات |
|---|---|---|
| العقود | `contracts/` | تُعدَّل أولاً وفي PR مستقل (contract-first — ADR-0002) |
| التطبيق الأساسي (Laravel) | `backend/api/**` | `generated/` مولَّد بـ `make gen` ولا يُعدّل يدوياً |
| خدمة المخزون (Spring Boot) | `backend/inventory/**` | |
| الواجهة (React) | `frontend/**` | `src/api/schema.d.ts` مولَّد بـ `make gen` |
| الاختبارات والإثباتات | `tests/e2e/` `tests/load/` `tests/security/` `tests/postman/` | |
| البنية التحتية والوثائق | `infra/` `compose*.yaml` `Makefile` `.github/` `docs/` `tasks/` | |
- كل commit يمسّ مكوّناً واحداً قدر الإمكان، ويُضاف بمسار صريح (`git add backend/api`) لا `git add -A`.

## 3. الفروع والـ Commits
- `main` محمي. فروع: `feat/r{N}-<scope>`, `fix/…`, `test/…`, `chore/…`, `docs/…`.
- Conventional Commits بالإنجليزية: `feat(orders): enforce owner-only access with 404 on foreign orders`.
- commit صغير لكل خطوة منطقية؛ لا commit يكسر البناء.
- Squash merge، تاريخ خطي.

## 4. تشغيل الفحوص قبل كل PR
| الأمر | ما يفحصه |
|---|---|
| `make lint-contracts` | `buf lint` + `buf breaking` + Redocly lint على OpenAPI |
| `make verify-api` | Pint + Larastan + Pest (بما فيها اختبارات الحدود Arch ومطابقة العقد) |
| `make verify-inventory` | Spotless + ErrorProne + اختبارات JUnit/Testcontainers + JaCoCo |
| `make verify-web` | ESLint + tsc strict + Vitest + build |
| `make verify` | كل ما سبق + E2E (بوابة الدمج، تطابق CI) |

## 5. طلبات الدمج (PR) — W-1, W-2
1. صاحب الفرع يكمل المهمة ⇒ `make verify-<component>` أخضر.
2. يكتب الوصف وفق القالب `.github/pull_request_template.md`.
3. **العضو المالك** يدفع الفرع ويفتح الـ PR من حسابه (`gh pr create`).
4. **العضو الآخر** يراجع على GitHub (يقرأ الكود فعلاً — سيُسأل عنه، W-3)، ويوافق ⇒ دمج.

### توزيع الـ PRs على العضوين (يضمن W-2 مع توازن الفهم)
| PR | المتطلب | المالك | المراجع |
|---|---|---|---|
| `chore/p0-contracts` (OpenAPI + proto + errors) | 1 | A | B |
| `chore/p0-platform` (compose, nginx, Makefile, CI) | 1 | B | A |
| `chore/p0-api-skeleton` · `test/p0-harness` | 1 | A | B |
| `chore/p0-inventory-skeleton` · `chore/p0-web-skeleton` | 1 | B | A |
| `feat/r1-auth-jwt` (Users + JWT + rate limit + headers + problem+json) | 1 | A | B |
| `feat/r1-products` (Products + images + pagination) | 1 | B | A |
| `feat/r1-orders-security` (Orders + Policies + PROOF-R1) | 1 | A | B |
| `feat/r1-notifications` | 1 | B | A |
| `feat/r1-web` (auth, catalog, cart, orders, admin) | 1 | B | A |
| `test/r1-security-proofs` (E2E + http + demo-r1) | 1 | A | B |
| `feat/r2-inventory-service` (Spring كامل + اختبارات التزامن) | 2 | B | A |
| `feat/r2-inventory-gateway` (gRPC client + deadlines + mapping + stock في المنتجات) | 2 | A | B |
| `feat/r2-order-saga` (الحجز + التعويض + journal + reconciler + إلغاء) | 2 | A | B |
| `feat/r2-web-stock-ux` | 2 | B | A |
| `test/r2-race-chaos` (k6 + toxiproxy + demo-r2) | 2 | B | A |
⇒ كل عضو له ≥ 4 PRs في المتطلب 1 و ≥ 2 في المتطلب 2، وكل عضو راجع جانب زميله.

## 6. حماية الفرع الرئيسي
> ملاحظة: حماية الفروع للمستودعات **الخاصة** تتطلب GitHub Pro/Team (مجاني للطلاب عبر GitHub Education) أو اجعل المستودع عاماً.
```bash
gh api -X PUT "repos/{owner}/{repo}/branches/main/protection" --input - <<'JSON'
{
  "required_status_checks": { "strict": true, "contexts": ["contracts", "api", "inventory", "web", "e2e"] },
  "enforce_admins": true,
  "required_pull_request_reviews": { "required_approving_review_count": 1, "dismiss_stale_reviews": true },
  "restrictions": null,
  "required_linear_history": true,
  "allow_force_pushes": false,
  "allow_deletions": false,
  "required_conversation_resolution": true
}
JSON
```
أسماء `contexts` يجب أن تطابق أسماء jobs في `.github/workflows/ci.yml`.

## 7. إيقاع كل مرحلة
1. قراءة ملف المرحلة في `tasks/` وتوزيع المهام على العضوين.
2. تنفيذ كل مهمة على فرعها ⇒ فحوص خضراء ⇒ PR ⇒ مراجعة الزميل ⇒ دمج.
3. نهاية المرحلة: `make verify` + `make demo-rN` من `main` + تحديث `docs/08-interview-prep.md`.
