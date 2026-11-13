# 10 — المعالجة غير المتزامنة والتحديث اللحظي (المتطلب 4)

> القرارات: ADR-0015 (RabbitMQ + Outbox + العمّال) و ADR-0016 (WebSocket بقنوات خاصة).
> الإثبات: `make demo-r4` (36 فحصاً على الحاويات الحقيقية).

## 1. الصورة العامة
```
المتصفح ──POST /orders──▶ api (Laravel)
                           │  معاملة واحدة: orders + outbox_messages
                           │  بعد الـ commit: نشر الحدث (بتأكيد) ← يرد 201 فوراً
                           ▼
                     RabbitMQ  exchange: orders.events (topic)
                       ├── notifications.mail ──▶ worker-mail ──▶ جدول notifications + بريد (SMTP)
                       └── realtime.orders ─────▶ worker-realtime ──▶ reverb ══WebSocket══▶ المتصفح
```
- **التطبيق لا ينتظر:** البريد والبث يحدثان خارج طلب HTTP. توقف RabbitMQ أو العامل أو خادم البريد لا يُفشل طلباً.
- **لكل طابور** طابوران مرافقان: `X.retry` (انتظار ثم إعادة) و `X.dlq` (الرسائل التي تفشل دائماً).

## 2. أين كل شيء في الكود
| الجزء | الملف |
|---|---|
| كتابة الحدث مع الطلب | `Orders/Actions/PlaceOrder.php` و `ChangeOrderStatus.php` ← `$this->outbox->record(...)` |
| الـ Outbox (كتابة + نشر + relay) | `Shared/Messaging/Outbox/Outbox.php` · جدول `outbox_messages` |
| النشر بتأكيد | `Shared/Messaging/Amqp/AmqpPublisher.php` |
| الطوابير (retry / dlq) | `Shared/Messaging/Amqp/Topology.php` · `config/messaging.php` |
| حلقة العامل (ack، إعادة، DLQ) | `Shared/Messaging/Amqp/AmqpConsumer.php` · أمر `messaging:consume` |
| عامل البريد | `Notifications/Messaging/SendOrderNotification.php` |
| عامل البث | `Orders/Realtime/BroadcastOrderUpdate.php` |
| القنوات وصلاحياتها | `Orders/Realtime/OrderChannels.php` · `OrdersServiceProvider.php` |
| نقطة التحقق قبل الاشتراك | `Shared/Realtime/AuthorizeChannelController.php` (`POST /broadcasting/auth`) |
| الواجهة | `frontend/src/realtime/socket.ts` · `useLiveEvents.ts` · `OrderDetailPage.tsx` · `AdminOrders.tsx` |
| الحاويات | `compose.yaml`: `rabbitmq`, `worker-mail`, `worker-realtime`, `reverb` · `infra/nginx/conf.d/edge.conf` (`/app/`) |

## 3. الأحداث (عقد الرسائل)
Exchange `orders.events`، مفتاح التوجيه = النوع، الجسم JSON، `message_id` = معرّف صف الـ outbox (ULID)، persistent.

| النوع | الحقول |
|---|---|
| `order.placed` | `order_id`, `user_id`, `status`, `total_minor`, `currency`, `item_count`, `created_at` |
| `order.status_changed` | `order_id`, `user_id`, `from`, `to`, `changed_at` |

## 4. عدم ضياع الرسائل — حالة بحالة
| ماذا يتعطل | ماذا يحدث |
|---|---|
| RabbitMQ متوقف عند إنشاء الطلب | الطلب يُحفظ ويرد 201؛ الصف يبقى في outbox؛ `outbox:relay` (كل 5 ث) ينشره عند عودة الوسيط |
| العملية تموت بين الـ commit والنشر | نفس الشيء: الصف محفوظ مع الطلب، والـ relay ينشره |
| العامل متوقف | الرسائل تنتظر في الطابور (durable + persistent)؛ عند تشغيله يعالجها كلها |
| العامل يتعطل أثناء رسالة | لم تُؤكَّد (ack يدوي) ← RabbitMQ يعيد تسليمها |
| خادم البريد لا يرد | استثناء ← نسخة إلى `notifications.mail.retry` (10 ث) ← تعود ← حتى 5 محاولات |
| الرسالة تفشل دائماً أو تالفة | تُنقل إلى `notifications.mail.dlq` مع سبب الفشل؛ لا تُحذف ولا تعطّل الطابور |
| الرسالة تصل مرتين | `notifications.message_id` فريد + `mailed_at` ← إشعار واحد وبريد واحد |

## 5. القنوات اللحظية والصلاحيات
| القناة | من يشترك | الأحداث |
|---|---|---|
| `private-orders.{orderId}` | الزبون مالك الطلب فقط | `order.status_changed` |
| `private-admin.orders` | المسؤول فقط | `order.placed`, `order.status_changed` |

خطوات الاشتراك: (1) المتصفح يفتح WebSocket على نفس العنوان `/app/<key>`. (2) قبل الاشتراك يطلب `POST /api/v1/broadcasting/auth` مع الـ JWT. (3) الـ API يتحقق من الهوية ثم من قاعدة القناة ثم يوقّع الاشتراك بسرّ لا يعرفه المتصفح. (4) Reverb يرفض أي اشتراك بلا توقيع صحيح.
النتائج: بلا توكن 401 · قناة ليست له 403 · توقيع مزوّر يرفضه Reverb نفسه.

## 6. البريد الحقيقي (Gmail SMTP)
الافتراضي Mailpit (`localhost:8025`). للإرسال الحقيقي ضع في `.env` ثم `make up`:
```
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=<حسابك>@gmail.com
MAIL_PASSWORD=<App Password من 16 حرفاً>
MAIL_FROM_ADDRESS=<حسابك>@gmail.com
```
App Password: حساب Google ← الأمان ← التحقق بخطوتين (يجب تفعيله) ← كلمات مرور التطبيقات. الزبائن التجريبيون بريدهم `@example.test` وهمي: للتجربة سجّل زبوناً ببريد حقيقي.

## 7. التجربة
| ماذا | كيف |
|---|---|
| كل الإثباتات آلياً | `make demo-r4` |
| واجهة RabbitMQ (الطوابير وأعدادها) | `http://localhost:15672` (المستخدم وكلمة المرور `RABBITMQ_USER` / `RABBITMQ_PASSWORD` من `.env`) |
| إيقاف/تشغيل العامل | Docker Desktop ← `worker-mail` ← ⏹ ثم ▶ (أو `docker compose stop/start worker-mail`) |
| سجل العامل | `make logs S=worker-mail` |
| التحديث اللحظي | نافذتان: مسؤول على `/admin`، وزبون على صفحة طلبه؛ أنشئ طلباً / غيّر حالته |

## 8. أسئلة متوقعة
- **لماذا outbox وليس نشر مباشر؟** الحفظ والنشر على نظامين؛ بدونه يضيع الحدث إن تعطل الوسيط بين الخطوتين.
- **هل قد يصل البريد مرتين؟** التسليم at-least-once، لذلك العامل idempotent بـ `message_id`. الحالة الوحيدة الباقية: يُرسَل البريد ثم يموت العامل قبل تسجيل `mailed_at` — نادرة ومقبولة (بريد مكرر أفضل من بريد ضائع).
- **ما الفرق بين retry و DLQ؟** retry لعطل مؤقت (خادم البريد)، DLQ لما لا أمل فيه؛ بدونه رسالة واحدة تالفة تدور للأبد وتحجز الطابور.
- **لماذا WebSocket وليس polling؟** لحظي فعلاً وبلا طلبات متكررة؛ والخادم هو من يدفع.
- **كيف منعتم متابعة طلب الغير؟** القناة خاصة: لا اشتراك بلا توقيع، ولا توقيع إلا بعد فحص المالك — نفس قاعدة `OrderPolicy` في REST.
