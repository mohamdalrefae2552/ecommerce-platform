# ADR-0005: أقفال صفوف تشاؤمية بترتيب حتمي + CHECK لمنع البيع الزائد
**الحالة:** مقبول

## السياق
PROOF-R2-1: طلبات متزامنة كثيرة على آخر قطعة ⇒ ينجح واحد فقط. الحجز متعدد البنود وذرّي (R2-F1).

## القرار
معاملة واحدة `READ COMMITTED`: `SELECT … WHERE product_id = ANY(:ids) ORDER BY product_id FOR UPDATE` ⇒ حساب كل النواقص ⇒ rollback كامل أو تحديث الكل. `CHECK (available >= 0)`. `lock_timeout` أقل من deadline العميل. Idempotency بـ PK على `reservations.id` و `ON CONFLICT DO NOTHING`. التفاصيل: `docs/05`.

## البدائل المرفوضة
تحديث شرطي فقط، Optimistic `version` + retry، `SERIALIZABLE`، advisory/Redis locks — الأسباب في `docs/05` §2.

## العواقب
+ صحة مضمونة ومثبتة باختبار تزامن حقيقي، بلا deadlocks، كل النواقص في رد واحد. − تسلسل على المنتج الساخن (مقبول: الحجز قصير جداً؛ يُقاس في المتطلب 3).
