# المتطلب 5 — التوسع وتوزيع الأحمال (Scalability & Load Distribution)

## 📍 الحالة — ✅ مكتمل (2026-11-20)
البنود في `docs/00-project-brief.md` (R5-1..R5-4، PROOF-R5-1..3). الشرح: `docs/11-scaling.md`. القرار: ADR-0017.

| # | الخطوة | البند | الحالة |
|---|---|---|---|
| 1 | `api` بثلاث نسخ افتراضياً (`API_REPLICAS`)، `make scale` | R5-1 | ✅ |
| 2 | `upstream api_pool` في edge: `least_conn` + `resolve` (DNS كل 5 ث) + تبرير (ADR-0017) | R5-1 | ✅ |
| 3 | failover: مهلة اتصال 1 ث، `fastcgi_next_upstream`، `max_fails/fail_timeout` | R5-3 | ✅ |
| 4 | `worker-mail` بثلاث نسخ على `notifications.mail` (`MAIL_WORKERS`) | R5-2 | ✅ |
| 5 | وضع الصيانة في Redis + رمز `SERVICE_UNAVAILABLE` (503) في العقد (اختبار أولاً) | R5-4 | ✅ |
| 6 | Reverb بنسختين + scaling عبر Redis؛ DNS cache للعامل 5 ث؛ retry اللحظي 5 × 4 ث | R5-4 | ✅ |
| 7 | `make scale-load` (500 طلب، من أجاب كلاً منها، `STOP=2`) | PROOF-R5-1/2 | ✅ |
| 8 | `make scale-problems` (P1..P5 قبل وبعد) و `make demo-r5` (12 فحصاً) | PROOF-R5-3 | ✅ |
| 9 | تعديل `demo-r4` ليعمل مع عدة عمّال | — | ✅ |

## الإثباتات
| ID | كيف | النتيجة |
|---|---|---|
| PROOF-R5-1 | `make scale-load` | 500 طلب ← api-1: 167، api-2: 166، api-3: 167؛ 0 فشل، 0 × 429 |
| PROOF-R5-2 | `make scale-load STOP=2` | api-2 أُوقف في المنتصف ← 500/500 نجحت، والحمل انتقل إلى api-1 و api-3 |
| PROOF-R5-3 | `make scale-problems` | P1 أبطأ طلب 5042 ← 68 ms · P2 صيانة 3/9 ← 9/9 · P3 أول 429 عند 16 ← 6 · P4 تعديل ظاهر 3/9 ← 9/9 · P5 أحداث 1/5 و 4/5 ← 5/5 و 5/5 |
| R5-2 | `make demo-r5` | 9 طلبات ← 3 / 3 / 3 رسائل لكل عامل، 9 رسائل بريد، لا تكرار |
