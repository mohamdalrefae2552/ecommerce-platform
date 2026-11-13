# المتطلب 4 — المعالجة غير المتزامنة والتحديثات اللحظية

## 📍 الحالة — ✅ مكتمل (2026-11-13)
البنود في `docs/00-project-brief.md` (R4-1..R4-6، PROOF-R4-1..3). الشرح: `docs/10-async-realtime.md`. القرارات: ADR-0015، ADR-0016.

| # | الخطوة | البند | الحالة |
|---|---|---|---|
| 1 | حاوية RabbitMQ + عميل AMQP (نشر بتأكيد، طوبولوجيا retry/dlq) | R4-1, R4-3 | ✅ |
| 2 | Transactional Outbox: الحدث يُكتب مع الطلب، يُنشر بعد الـ commit، و `outbox:relay` للمعلّق | R4-1 | ✅ |
| 3 | عامل مستقل `worker-mail`: إشعار + بريد، idempotent، ack يدوي | R4-2, R4-3 | ✅ |
| 4 | إعادة المحاولة (5 × 10 ث) ثم `notifications.mail.dlq` | R4-3 | ✅ |
| 5 | Reverb + `worker-realtime` + قناتان خاصتان + `POST /broadcasting/auth` (في العقد) | R4-4..R4-6 | ✅ |
| 6 | الواجهة: صفحة الطلب ولوحة المسؤول تتحدثان لحظياً | R4-4, R4-5 | ✅ |
| 7 | الإثبات `make demo-r4` + اختبارات Pest و Vitest + Postman (مجلد 6) | PROOF-R4-1..3 | ✅ |
| 8 | بريد حقيقي عبر Gmail SMTP | R4-2 | ⏳ يحتاج `MAIL_*` في `.env` من صاحب الحساب (`docs/10` §6)؛ الافتراضي Mailpit |

## الإثباتات
| ID | كيف | النتيجة |
|---|---|---|
| PROOF-R4-1 | `make demo-r4`: إيقاف `worker-mail`، 3 طلبات، تشغيله | 3 رسائل تنتظر ← 3 رسائل بريد، واحدة لكل طلب |
| PROOF-R4-2 | مشترك في `private-admin.orders` ثم `POST /orders` | يصله `order.placed` بمعرّف الطلب |
| PROOF-R4-3 | مالك الطلب مشترك في `private-orders.{id}` ثم المسؤول يؤكد | يصله `order.status_changed` (pending ← confirmed) |
| الصلاحيات | زبون آخر / زبون على قناة المسؤول / مسؤول على قناة زبون / بلا توكن / توقيع مزوّر | 403 / 403 / 403 / 401 / يرفضه Reverb |

## ما لم يُبنَ (خارج المطلوب)
- إعادة آلية من DLQ (تُعالج يدوياً من واجهة RabbitMQ).
- تعدد نسخ Reverb (المتطلب 5).
