# EXPLAIN ANALYZE — الاستعلامات قبل وبعد (المتطلب 3، R3-2)

البيانات: `make perf-seed` — ‏200,000 طلب، 500,532 سطر طلب، 639,536 سطر في سجل الحالات، 2,020 منتجاً.
الأمر: `EXPLAIN (ANALYZE, BUFFERS, COSTS OFF) <الاستعلام>` داخل `db-app` (PostgreSQL 18)، والاستعلامات هي نفسها التي يولّدها Laravel لكل endpoint (القيم مكتوبة حرفياً بدل `?`).
الملف الكامل لكل الخطط قبل التعديل: [`explain-before.txt`](explain-before.txt)، وبعد إضافة الفهرس (Q1 و Q2، الوحيدان اللذان يمسّهما): [`explain-after.txt`](explain-after.txt).

## كيف وجدنا الاستعلام البطيء
بعد تشغيل `make perf-test OUT=baseline` قرأنا إحصاءات الجداول (`pg_stat_user_tables`):

| الجدول | seq_scan | seq_tup_read | idx_scan |
|---|---:|---:|---:|
| orders | 145 | 29,002,465 | 1,144,170 |
| cache | 73,598 | 12,658,383 | 902 |
| users | 11,979 | 340,611 | 0 |

- `orders`: ‏145 مسحاً كاملاً قرأت 29 مليون صف ⇐ كل صفحة من قائمة المسؤول (90 طلباً في الاختبار) تقرأ الجدول كله.
- `cache`: ‏73,598 قراءة و 12,125 تعديلاً على جدول الكاش في Postgres — هذا هو **محدِّد المعدّل** (`throttle:api`، 120/دقيقة) لأن مخزن الكاش كان `database`: كل طلب HTTP = عدة استعلامات وكتابة على نفس الصف لكل مستخدم. يُحل بنقل الكاش إلى Redis (الخطوة 4)، لا بفهرس.
- `users`: جدول صغير (62 صفاً) فيختار المخطط Seq Scan عن قصد؛ ليس مشكلة.

## الاستعلامات وخطة كل منها
| # | Endpoint | الاستعلام | الخطة قبل | الزمن قبل | القرار |
|---|---|---|---|---:|---|
| Q1 | `GET /orders` (المسؤول) | `count(*)` على كل الطلبات (للـ `meta.total`) | Parallel Index Only Scan | 14 ms | يبقى: العدّ الدقيق لـ 200 ألف صف يقرأ فهرساً كاملاً مهما فعلنا (انظر "ما لم نغيّره") |
| **Q2** | `GET /orders?page=10` (المسؤول) | `order by created_at desc, id desc limit 20 offset 180` | **Seq Scan 200,017 صف + top-N heapsort** | **32.3 ms** | **فهرس `orders(created_at, id)`** |
| Q3 | `GET /orders?status=shipped` (المسؤول) | نفسه مع `where status = ?` | Index Scan Backward على `(status, created_at)` + Incremental Sort | 1.0 ms | موجود منذ المتطلب 1 |
| Q4 | `GET /orders` (زبون له 2,983 طلباً) | `where user_id = ? order by created_at desc, id desc` | Index Scan Backward على `(user_id, created_at)` | 0.7 ms (+ count 0.4 ms) | موجود منذ المتطلب 1 |
| Q5 | `GET /products?page=10` | `order by created_at desc, id desc` | Index Scan Backward على `(created_at, id)` | 0.3 ms | موجود |
| Q6 | `GET /products?category=books` | `where exists (… slug = ?)` + ترتيب | Bitmap على `category_id` + Sort لـ 365 صفاً | 0.4 ms | كافٍ لهذا الحجم |
| Q7 | `GET /orders/{id}` | أسطر الطلب + سجل الحالات (eager loading) | Index على `order_items(order_id, product_id)` و `order_status_history(order_id, id)` | < 1 ms | موجود |

`items_count` في القوائم استعلام فرعي واحد لكل صف من الصفحة عبر Index Only Scan (`withCount('items')`) داخل الاستعلام نفسه، وليس N+1 من PHP.

## Q2 قبل الفهرس
```
                                                                   QUERY PLAN                                                                   
------------------------------------------------------------------------------------------------------------------------------------------------
 Limit (actual time=32.160..32.211 rows=20.00 loops=1)
   Buffers: shared hit=3887
   ->  Result (actual time=31.709..32.199 rows=200.00 loops=1)
         Buffers: shared hit=3887
         ->  Sort (actual time=31.620..31.633 rows=200.00 loops=1)
               Sort Key: orders.created_at DESC, orders.id DESC
               Sort Method: top-N heapsort  Memory: 73kB
               Buffers: shared hit=3286
               ->  Seq Scan on orders (actual time=0.012..13.998 rows=200017.00 loops=1)
                     Buffers: shared hit=3280
         SubPlan 1
           ->  Aggregate (actual time=0.002..0.002 rows=1.00 loops=200)
                 Buffers: shared hit=601
                 ->  Index Only Scan using order_items_order_id_product_id_unique on order_items (actual time=0.002..0.002 rows=2.37 loops=200)
                       Index Cond: (order_id = orders.id)
                       Heap Fetches: 0
                       Index Searches: 200
                       Buffers: shared hit=601
 Planning:
   Buffers: shared hit=109
 Planning Time: 0.808 ms
 Execution Time: 32.307 ms
(22 rows)
```

## Q2 بعد `CREATE INDEX orders_created_at_id_index ON orders (created_at, id)`
```
                                                                   QUERY PLAN                                                                   
------------------------------------------------------------------------------------------------------------------------------------------------
 Limit (actual time=0.826..0.889 rows=20.00 loops=1)
   Buffers: shared hit=785 read=4
   ->  Index Scan Backward using orders_created_at_id_index on orders (actual time=0.076..0.876 rows=200.00 loops=1)
         Index Searches: 1
         Buffers: shared hit=785 read=4
         SubPlan 1
           ->  Aggregate (actual time=0.003..0.003 rows=1.00 loops=200)
                 Buffers: shared hit=601
                 ->  Index Only Scan using order_items_order_id_product_id_unique on order_items (actual time=0.002..0.002 rows=2.37 loops=200)
                       Index Cond: (order_id = orders.id)
                       Heap Fetches: 0
                       Index Searches: 200
                       Buffers: shared hit=601
 Planning:
   Buffers: shared hit=107
 Planning Time: 0.981 ms
 Execution Time: 0.933 ms
(17 rows)
```
**32.3 ms ⇐ 0.9 ms (أسرع 35 مرة).** الخطة صارت `Index Scan Backward`: PostgreSQL يمشي الفهرس من الأحدث ويتوقف بعد `offset + limit` = 200 صف بدل قراءة 200,017 صفاً وترتيبها. وكلما زاد عدد الطلبات بقي الزمن ثابتاً تقريباً، بينما كان Seq Scan يكبر خطياً.

الفهرس أُضيف بالهجرة `backend/api/app/Modules/Orders/Database/Migrations/2026_11_06_000100_add_orders_created_at_index.php` (وحدة Orders تملك جدولها). ثمن الفهرس: كتابة إضافية صغيرة عند كل `INSERT` طلب، ومساحة ~10 MB لـ 200 ألف صف — مقبول لأن قراءة القائمة أكثر بكثير من إنشاء الطلبات.

## N+1 (R3-3)
- فحصنا كل endpoint قائمة: المنتجات تستعمل `with('category')`، الطلبات `withCount('items')`، تفاصيل الطلب `with(['items', 'history'])`، الإشعارات بلا علاقات. لا N+1 حالياً.
- **حتى لا يظهر لاحقاً:** `Model::preventLazyLoading()` مفعّل خارج الإنتاج (`App\Shared\Database\DatabaseServiceProvider`): أي قراءة علاقة بلا eager loading ترمي استثناءً في الاختبارات فوراً بدل أن تصبح استعلاماً لكل صف بصمت.
- اختبار `tests/Feature/Performance/QueryCountTest.php`: كل قائمة تنفّذ **نفس عدد الاستعلامات** لصفّين ولعشرين صفاً (منتجات، طلبات الزبون، كل الطلبات للمسؤول، الإشعارات، تفاصيل طلب بعشرة أسطر).

## ما لم نغيّره ولماذا
- **عدّ كل الطلبات (Q1، 14 ms):** `meta.total` في العقد عدد دقيق؛ `count(*)` على 200 ألف صف يقرأ فهرساً كاملاً. البدائل (تقدير `pg_class.reltuples`، أو ترقيم keyset بلا total) تغيّر العقد، فتُركت كقرار مستقبلي.
- **فهارس ثلاثية `(user_id, created_at, id)` و `(status, created_at, id)`:** كانت ستلغي خطوة Incremental Sort الصغيرة في Q3/Q4، لكن الزمن أصلاً < 1 ms؛ لا تبررها الخطة.
- **`products(category_id, created_at, id)`:** فرز 365 صفاً يكلّف 0.4 ms؛ لا يستحق فهرساً الآن (ويصبح مفيداً حين يتجاوز التصنيف عشرات الآلاف من المنتجات).
