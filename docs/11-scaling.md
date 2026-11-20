# 11 — التوسع وتوزيع الأحمال (المتطلب 5)

> القرار: ADR-0017. الإثباتات: `make scale-load` (500 طلب)، `make demo-r5` (12 فحصاً)، `make scale-problems` (كل مشكلة قبل الإصلاح وبعده).

## 1. الصورة العامة
```
                     ┌── api-1 ─┐
المتصفح ── edge (nginx) ─┼── api-2 ─┼──▶ Postgres (أصل + نسخة قراءة) · Redis · RabbitMQ · inventory (gRPC)
   upstream api_pool     └── api-3 ─┘
   least_conn + failover          │
                                  ▼ RabbitMQ: notifications.mail ──▶ worker-mail-1 / -2 / -3
                                               realtime.orders   ──▶ worker-realtime ──▶ reverb-1 / reverb-2 (Redis pub/sub)
```
| الخدمة | عدد النسخ | المتغير |
|---|---|---|
| `api` | 3 | `API_REPLICAS` |
| `worker-mail` | 3 | `MAIL_WORKERS` |
| `reverb` | 2 | `REVERB_REPLICAS` |
| `edge`, `scheduler`, `worker-realtime`, قواعد البيانات، Redis، RabbitMQ | 1 | — |

تغيير العدد وهو يعمل: `make scale API=4 MAIL_WORKERS=3`.

## 2. البنود وأين نُفّذت
| البند | التنفيذ | أين |
|---|---|---|
| **R5-1** 3 نسخ خلف موزّع + تبرير | `upstream api_pool` بـ `least_conn` و `resolve` (DNS كل 5 ث) | `infra/nginx/conf.d/edge.conf` (أعلى الملف) · `compose.yaml` (`api.deploy.replicas`) · ADR-0017 |
| **R5-2** 3 عمّال على طابور واحد | مستهلكون متنافسون على `notifications.mail`، `prefetch=1`، معالج idempotent | `compose.yaml` (`worker-mail.deploy.replicas`) · `AmqpConsumer.php` · `SendOrderNotification.php` |
| **R5-3** الاستمرارية | مهلة اتصال 1 ث + إعادة الإرسال لنسخة أخرى + استبعاد النسخة 10 ث | `edge.conf` (`fastcgi_next_upstream`، `max_fails`) |
| **R5-4** المشاكل الخفية | خمس مشاكل وُجدت وأُصلحت (القسم 3) + جرد الحالة المحلية (القسم 4) | `make scale-problems` |

### لماذا least_conn؟
- الطلبات غير متساوية: صفحة كتالوج من الكاش ≈ ميلي ثوانٍ، وإنشاء طلب ينتظر المخزن عبر gRPC وقاعدة البيانات (حتى 3 ث إن كان المخزن بطيئاً).
- **round-robin** يوزّع **العدد** بالتساوي: نسخة علقت بطلبات بطيئة تستمر في استقبال حصتها، فتنتظر الطلبات خلفها.
- **least_conn** يرسل للنسخة الأقل انشغالاً الآن. وعند الحمل المتساوي يتصرف كـ round-robin (500 طلب ← 167 / 166 / 167).
- **ip_hash** مرفوض: لا نحتاج الالتصاق لأن النسخ بلا حالة، والمستخدمون خلف NAT يأتون من IP واحد فيذهبون لنسخة واحدة.

## 3. المشاكل الخفية — قبل وبعد (`make scale-problems`)
| # | المشكلة | السبب | الإصلاح | قبل | بعد |
|---|---|---|---|---|---|
| P1 | توقف نسخة تحت الضغط | الإعداد السابق في nginx (`set $upstream` + resolver) يوزّع على عناوين DNS بلا ذاكرة أعطال، ومهلة الاتصال 5 ث: كل طلب يصيب النسخة المتوقفة ينتظر 5 ث | `upstream` بـ `least_conn`، مهلة اتصال 1 ث، `fastcgi_next_upstream error timeout`، `max_fails=1 fail_timeout=10s` | أبطأ طلب **5042 ms** | أبطأ طلب **68 ms**، 0 فشل |
| P2 | وضع الصيانة على نسخة واحدة | `php artisan down` يكتب ملف `storage/framework/down` **داخل الحاوية** التي نُفّذ فيها؛ وكانت النسخة ترد 500 لا 503 | `APP_MAINTENANCE_DRIVER=cache` على Redis؛ ورمز خطأ جديد `SERVICE_UNAVAILABLE` (503 + `Retry-After`) | **3/9** طلبات ترد 503، و6 تمر | **9/9** ترد 503 |
| P3 | حد محاولات الدخول ×3 | لو كان الكاش ملفات محلية، كل نسخة تعدّ المحاولات وحدها: المهاجم يحصل على 5 محاولات في كل نسخة | الكاش وعدّادات الحد في Redis (`CACHE_STORE=redis`) | أول 429 عند المحاولة **16** | أول 429 عند المحاولة **6** |
| P4 | منتج معدّل يبقى قديماً | الكاش المحلي: إلغاؤه بعد التعديل يحدث في النسخة التي استقبلت التعديل فقط | الكاش ورقم إصداره في Redis (ADR-0013) | **3/9** قراءات تُظهر الاسم الجديد | **9/9** |
| P5 | تحديثات لحظية تضيع | (أ) كل خادم Reverb يعرف مشتركيه فقط: الحدث يصل لمن على الخادم الذي استقبله. (ب) العامل يحتفظ بعناوين DNS 60 ث (cURL)، فبعد استبدال خوادم Reverb ظل يتصل بعناوين ميتة | (أ) `REVERB_SCALING_ENABLED` عبر Redis pub/sub. (ب) `CURLOPT_DNS_CACHE_TIMEOUT=5` و 5 محاولات خلال ~20 ث لطابور اللحظي | الخادمان: **1/5** و **4/5** | **5/5** و **5/5** |

كيف يُعرض "قبل": السكربت يعيد الإعداد القديم مؤقتاً (كاش وملف صيانة محليان، Reverb بلا Redis، وحاوية edge إضافية بإعداد المتطلب الرابع)، يقيس، ثم يعيد الإعداد الطبيعي ويقيس مرة أخرى.

## 4. جرد "كل ما يُحفظ محلياً داخل النسخة"
| الشيء | أين كان / يمكن أن يكون محلياً | الوضع الآن | الدليل |
|---|---|---|---|
| جلسة المستخدم | sessions في ملفات | لا sessions: JWT (`SESSION_DRIVER=array`) | نفس التوكن مقبول من النسخ الثلاث (`demo-r5`) |
| مفاتيح توقيع JWT | `storage/keys` | volume `jwt-keys` مشترك | — |
| refresh tokens | — | Postgres | — |
| الكاش وعدّادات حد المحاولات | ملفات أو ذاكرة | Redis | P3، P4 |
| قفل Idempotency-Key | قفل ملف | Redis | 3 طلبات بنفس المفتاح في نفس اللحظة ← طلب واحد (`demo-r5`) |
| علامة read-your-writes | — | Redis | `make replica-check` |
| وضع الصيانة | `storage/framework/down` | Redis | P2 |
| صور المنتجات | `storage/app/public` | volume `product-images` مشترك مع edge | — |
| مشتركو WebSocket | ذاكرة خادم Reverb | تُوزَّع الأحداث عبر Redis | P5 |
| عناوين DNS في العامل | ذاكرة cURL (60 ث) | 5 ث | P5 |
| السجلات | ملفات `storage/logs` | stderr ← Docker؛ كل طلب يحمل `X-Request-Id` وسجل edge يذكر النسخة (`upstream`) | `make logs S=edge` |
| opcache والإعداد المترجم | داخل الصورة | متطابق في كل النسخ (نفس الصورة) | — |

## 5. حدود معروفة
- **edge نسخة واحدة:** نقطة فشل وحيدة على جهاز واحد. في الإنتاج: موزّع أمامه أو IP عائم (keepalived).
- **ميزانية اتصالات Postgres:** النسخ × `PHP_FPM_MAX_CHILDREN` (3 × 16 = 48) + العمّال ≤ `max_connections` (100). ست نسخ تتجاوزها: الحل pgbouncer أو تقليل `PHP_FPM_MAX_CHILDREN`.
- **قواعد البيانات و Redis و RabbitMQ** نسخة كتابة واحدة لكل منها (+ نسخة القراءة من المتطلب 3).
- **`scheduler`** نسخة واحدة عمداً.
- **تغيير إعدادات طابور موجود في RabbitMQ** (مثل TTL طابور retry) يرفضه الوسيط عند إعادة التعريف (`PRECONDITION_FAILED`): يُحذف الطابور القديم الفارغ أولاً.

## 6. التجربة
```bash
make up                        # 3 api, 3 mail workers, 2 reverb
make scale-load                # 500 requests, who answered each
make scale-load STOP=2         # the same, api-2 stopped after 6 s
make demo-r5                   # 12 checks: spread, a 4th replica, continuity, 3 workers, shared state
make scale-problems            # P1..P5 before and after (~4 min)
make scale API=4               # change the number of replicas live
make logs S=edge               # "upstream" = the replica that answered
```

## 7. أسئلة متوقعة
- **لماذا least_conn؟** الطلبات غير متساوية الكلفة؛ يرسل للنسخة الأقل انشغالاً، ويساوي round-robin حين يتساوى الحمل.
- **كيف يعرف nginx النسخ؟** DNS الخاص بـ Docker يعيد عنواناً لكل حاوية، و `resolve` يعيد السؤال كل 5 ثوانٍ؛ رأينا نسخة رابعة تستقبل طلبات دون إعادة تشغيل edge.
- **ماذا لو توقفت نسخة أثناء طلب POST؟** إن لم يُفتح الاتصال يُرسل لغيرها. إن كان قد وصل إليها فلا يعاد (لا طلب مكرر)، ويحصل المستخدم على خطأ واضح؛ ومفتاح Idempotency يسمح له بإعادة الإرسال بأمان.
- **كيف تتقاسم 3 عمّال الطابور دون تكرار؟** RabbitMQ يسلّم الرسالة لمستهلك واحد، ولا يعطيه غيرها قبل ack (`prefetch=1`)؛ والمعالج يتجاهل الرسالة إن عولجت سابقاً.
- **ما أخطر مشكلة وجدتموها؟** P3: لو كان حد المحاولات محلياً، لصار حد تسجيل الدخول 15 محاولة بدل 5 مع 3 نسخ — ثغرة أمنية لا تظهر إلا مع التوسع.
- **ما الذي لم يُوسَّع؟** edge وقواعد البيانات و Redis و RabbitMQ (القسم 5).
