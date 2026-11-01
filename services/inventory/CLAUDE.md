# services/inventory — Spring Boot 4.1 / Java 25 (المالك: spring-engineer)

## المسؤولية
**كل إدارة المخزون:** معرفة الكمية (GetStock)، الحجز الذري (ReserveStock)، الإرجاع (ReleaseStock)، تعديل المسؤول (AdjustStock). قاعدة `db-inventory` ملك هذه الخدمة وحدها. الاتصال الوحيد: gRPC على 9090 داخل `inventory-net`.

## الأوامر
| الأمر | الوظيفة |
|---|---|
| `make verify-inventory` | Spotless + ErrorProne + اختبارات + JaCoCo |
| `make test-inventory T=ReservationConcurrencyIT` | اختبار محدد |
| `docker compose exec toolbox grpcurl -plaintext inventory:9090 list` | فحص الخادم |
| `docker compose exec toolbox psql "$INVENTORY_DB_URL"` | فحص القاعدة (تشخيص فقط) |
Gradle يعمل داخل حاوية البناء (لا يلزم JDK محلياً)؛ Testcontainers يحتاج Docker socket.

## الاتفاقيات
- الخوارزميات في `docs/05` **حرفياً**؛ أي انحراف ⇒ `[QUESTION]`.
- `domain/` بلا أي import من Spring. `@Transactional` في `application/` فقط.
- SQL صريح في `persistence/` بنصوص `"""text blocks"""`؛ معاملات مسماة.
- records و sealed interfaces للنتائج (`ReserveResult.Reserved | Insufficient | AlreadyReleased`).
- لا `null` في واجهات الطبقات؛ `Optional` للإرجاع فقط.
- اختبارات التزامن: `ExecutorService` + `CountDownLatch` بدء مشترك + تحقق من حالة DB النهائية، و `@RepeatedTest`.
