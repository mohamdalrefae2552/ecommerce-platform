# ADR-0008: PostgreSQL 18 مستقلة لكل خدمة + شبكات معزولة
**الحالة:** مقبول (اختيار الفريق)

## السياق
R2-S2: قاعدة مستقلة للمستودع لا يقرأها التطبيق.

## القرار
`db-app` و `db-inventory` حاويتان منفصلتان (`postgres:18-alpine`)، volumes منفصلة، شبكتا Docker منفصلتان (`app-net`, `inventory-net`) و `api` ليس على `inventory-net`.
Postgres لأن: `SELECT … FOR UPDATE` مع إعادة تقييم الصف في READ COMMITTED، `ON CONFLICT`، قيود CHECK فعلية، `lock_timeout`/`statement_timeout`.

## البدائل المرفوضة
- **قاعدة واحدة بـ schemas:** يخالف روح R2-S2 ويصعب إثباته.
- **MySQL:** صالح، لكن الفريق اختار Postgres لاتساق السلوك بين الخدمتين.

## العواقب
+ العزل مثبت شبكياً. − حاويتا DB في التطوير (موارد إضافية مقبولة).
