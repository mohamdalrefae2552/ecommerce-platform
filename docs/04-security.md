# 04 — الأمان (R1-SEC1..SEC5)

## 1. نموذج التهديد — OWASP API Security Top 10 (2023)
| الخطر | تطبيقه عندنا | الضابط | الإثبات (اختبار) |
|---|---|---|---|
| API1 BOLA | زبون يفتح `/orders/{id}` لغيره | Policy `view` + استعلام مقيد بـ `user_id` + 404 | `PROOF-R1-1`، `OrdersAccessTest` |
| API2 Broken Authentication | تخمين كلمات المرور، توكن مزوّر، `alg:none` | JWT EdDSA بخوارزمية مثبتة، تحقق iss/aud/exp/nbf، Rate limit، refresh مُدوَّر بكشف إعادة الاستخدام | `JwtGuardTest`، `LoginThrottleTest`، `RefreshRotationTest` |
| API3 BOPLA | تمرير `role:"admin"` في التسجيل، تسريب `password` | `$fillable` صريح، FormRequest لا يقبل `role`، Resources بقائمة حقول بيضاء | `MassAssignmentTest`، snapshot للـ Resources |
| API4 Unrestricted Resource Consumption | `per_page=100000`، صور ضخمة | حدود صارمة + 422، `client_max_body_size`، rate limit عام | `PaginationLimitsTest`، `ImageUploadTest` |
| API5 BFLA | زبون يغيّر حالة طلب / يضيف منتجاً | Policies بالأدوار + middleware `role:admin` على المجموعة | `PROOF-R1-2`، `AdminOnlyEndpointsTest` (dataset لكل endpoint) |
| API6 Sensitive Business Flows | تكرار إنشاء الطلب، استنزاف المخزون | Idempotency-Key، حد كمية/بنود، rate limit على `POST /orders` | `IdempotencyTest` |
| API7 SSRF | لا جلب لروابط خارجية | لا يوجد مدخل URL | — |
| API8 Security Misconfiguration | debug مفعّل، ترويسات ناقصة | `APP_DEBUG=false` خارج local، Middleware ترويسات، `expose_php=Off`، `server_tokens off` | `SecurityHeadersTest`، فحص `curl -I` في demo |
| API9 Improper Inventory Management | endpoints غير موثقة | عقد OpenAPI + اختبار يتأكد أن كل route مسجل موجود في العقد والعكس | `RoutesMatchContractTest` |
| API10 Unsafe Consumption of APIs | الثقة بردود المستودع | تحقق من ردود gRPC (§4 في 03) + deadlines | `GrpcInventoryGatewayTest` |

## 2. المصادقة — JWT (R1-SEC1)
**القرار (ADR-0003):** Access token قصير + Refresh token معتم مُدوَّر.

| البند | القيمة |
|---|---|
| المكتبة | `lcobucci/jwt` (v5) داخل Guard مخصص `jwt` (`Auth::extend`) — بلا حزم سحرية |
| الخوارزمية | **EdDSA (Ed25519)** مثبتة في المُحقِّق؛ أي `alg` آخر (بما فيه `none`/`HS256`) يُرفض |
| المفاتيح | تُولَّد بـ `php artisan jwt:keys` في volume غير مُلتزَم (`storage/keys`, صلاحيات 0600)؛ `kid` في الترويسة لدعم التدوير |
| Claims | `iss`, `aud=ecommerce-api`, `sub`=user ULID, `role`, `iat`, `nbf`, `exp`, `jti` |
| مدة Access | 15 دقيقة، `leeway` 30 ثانية |
| التحقق | التوقيع، `iss`، `aud`، `exp`، `nbf`، وجود المستخدم وأنه غير معطّل. الدور يُقرأ من **قاعدة البيانات** عند كل طلب لا من الـ claim (الـ claim للعرض فقط) — كي يسري سحب الصلاحية فوراً |
| Refresh | 256-bit عشوائي، يُخزَّن **مُجزّأ** (SHA-256) في `refresh_tokens(id, user_id, family_id, token_hash, expires_at, revoked_at, replaced_by)`؛ مدة 14 يوماً |
| التدوير | كل `/auth/refresh` يلغي القديم ويصدر جديداً بنفس `family_id`. **استخدام توكن ملغى ⇒ إلغاء العائلة كلها** (كشف سرقة) + 401 |
| النقل | Access في جسم الاستجابة → يُحفظ **في ذاكرة** الواجهة فقط (لا localStorage). Refresh في كوكي `refresh_token` بـ `HttpOnly; Secure; SameSite=Strict; Path=/api/v1/auth` (لم نستخدم البادئة `__Host-` لأنها تفرض `Path=/` فيُرسل الكوكي مع كل طلب؛ تضييق المسار أهم هنا) |
| CSRF على refresh | `SameSite=Strict` + ترويسة مخصصة إلزامية `X-Requested-With: fetch` + فحص `Origin` مقابل `APP_URL` |
| كلمات المرور | `Hash` بـ **argon2id** (`config/hashing.php`)؛ مقارنة زمنها ثابت؛ عند بريد غير موجود نُجري `Hash::check` وهمي لمساواة التوقيت |
| ملاحظة HTTP محلي | `Secure` يمنع الكوكي على http إلا localhost (المتصفحات الحديثة تعامل `localhost` كسياق آمن). وثّق ذلك في README |

## 3. تحديد محاولات الدخول (R1-SEC2)
`RateLimiter::for(...)` في `UsersServiceProvider`:
| المحدد | المفتاح | الحد |
|---|---|---|
| `login` | `lower(email)|ip` | 5 / دقيقة |
| `login-ip` | `ip` | 20 / دقيقة (يمنع رش كلمات المرور على حسابات متعددة) |
| `register` | `ip` | 5 / ساعة |
| `refresh` | `ip` | 30 / دقيقة |
| `api` (عام) | `user_id` أو `ip` | 120 / دقيقة |
| `orders-create` | `user_id` | 10 / دقيقة |
- الرد: 429 problem+json `RATE_LIMITED` + `Retry-After` + `X-RateLimit-Limit/Remaining`.
- المخزن: cache store `redis` منذ المتطلب 3 (ADR-0013)؛ الاختبارات `array`. **IP الحقيقي:** `TrustProxies` يثق بـ `edge` فقط (عنوان شبكة Docker)، لا بـ `*`.
- الدخول الناجح يصفّر عداد `login` لذلك البريد.

## 4. ترويسات الأمان (R1-SEC3)
### على ردود الـ API (Middleware `SecurityHeaders`)
```
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'
Referrer-Policy: no-referrer
Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()
Cross-Origin-Opener-Policy: same-origin
Cross-Origin-Resource-Policy: same-origin
Cache-Control: no-store            (للردود المصادَق عليها)
Strict-Transport-Security: max-age=31536000; includeSubDomains   (فقط إن كان الطلب HTTPS)
```
وإزالة: `X-Powered-By` (`expose_php=Off`)، `Server` version (`server_tokens off`).
### على الواجهة (nginx `edge`)
CSP مناسبة لـ SPA: `default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'` + بقية الترويسات أعلاه. Swagger UI على `/docs` بـ CSP خاصة به (يحتاج inline styles).

## 5. التفويض (R1-SEC4, R1-SEC5)
- `OrderPolicy::view(User $u, Order $o)`: `$u->isAdmin() || $o->user_id === $u->id`. عند الرفض في `show` ⇒ نُرجع **404** (D-02) عبر `Response::denyAsNotFound()`.
- `OrderPolicy::changeStatus(User $u)`: `$u->isAdmin()` ⇒ وإلا **403**.
- `index`: للزبون `Order::query()->whereBelongsTo($user)`؛ لا يوجد طريق كود يجلب الكل ثم يصفي.
- ULIDs تقلل قابلية التخمين **لكنها ليست ضابطاً أمنياً**؛ الاختبارات تستخدم معرّفات حقيقية لطلبات مستخدم آخر.
- الصلاحيات مختبرة بـ dataset يغطي **كل** endpoint × كل دور (المصفوفة في `02` §3) — اختبار واحد يفشل إن أُضيف route بلا تغطية.

## 6. التحقق من المدخلات والملفات
- FormRequest لكل endpoint، `bail`، رفض الحقول غير المعروفة في الجسم (`prohibited` للحقول الحساسة مثل `role`, `user_id`, `status` في الإنشاء).
- الصور: تحقق MIME بالمحتوى (`mimetypes`) لا بالامتداد، إعادة ترميز WebP بـ `intervention/image` v3، تخزين خارج document root للـ PHP، اسم ULID.

## 7. الأسرار والإعداد
- `.env.example` فقط في Git. `APP_KEY` و مفاتيح JWT تُولَّد في `make bootstrap`.
- مستخدمو قواعد البيانات: مستخدم تطبيق بصلاحيات DML فقط على schema الخاصة به؛ الهجرات بمستخدم منفصل (اختياري متقدم — يُقرَّر في P0).
- الحاويات تعمل كـ non-root؛ `read_only: true` حيث أمكن.

## 8. سيناريوهات العرض (Demo)
`tests/security/http/` + `make demo-r1`:
1. تسجيل زبونين A و B، A ينشئ طلباً، B يطلب `GET /orders/{idA}` ⇒ 404.
2. B يرسل `PATCH /orders/{idA}/status` ⇒ 403؛ ثم A نفسه ⇒ 403.
3. 6 محاولات دخول خاطئة ⇒ السادسة 429 مع `Retry-After`.
4. توكن معدّل (تغيير `role` في الحمولة) ⇒ 401؛ `alg:none` ⇒ 401؛ منتهي ⇒ 401 `TOKEN_EXPIRED`.
5. `curl -sI` يعرض ترويسات الأمان.
6. إعادة استخدام refresh قديم ⇒ 401 وإلغاء الجلسة الأخرى.
