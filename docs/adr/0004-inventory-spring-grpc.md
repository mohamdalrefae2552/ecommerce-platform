# ADR-0004: خدمة المخزون بـ Spring Boot 4.1 + gRPC
**الحالة:** مقبول

## السياق
R2-S1..S3: خدمة بلغة مختلفة، قاعدة مستقلة، عقد proto.

## القرار
- Java 25 (LTS) + Spring Boot 4.1 مع `spring-boot-starter-grpc-server` (دعم gRPC الرسمي المدمج منذ 4.1، و `@GrpcAdvice` لتحويل الاستثناءات).
- Gradle Kotlin DSL + `com.google.protobuf` plugin يقرأ `contracts/proto` مباشرة.
- JdbcClient + SQL صريح + Flyway (لا JPA) للتحكم بالأقفال.
- عميل PHP: `grpc/grpc` + `google/protobuf` + `ext-grpc`.
- النقل داخل الشبكة: plaintext (insecure). **دَين تقني موثَّق:** mTLS عند متطلب التوسع.

## البدائل المرفوضة
- **Go/Node للمستودع:** ممكنة، لكن اختيار الفريق Spring Boot.
- **JPA/Hibernate:** يخفي الأقفال وترتيبها ويصعّب إثبات السلوك.
- **REST بدل gRPC:** مخالف للنص.
- **spring-grpc 1.0 starters القديمة (`org.springframework.grpc`):** مُهملة لصالح starters Boot 4.1.

## العواقب
+ عقد صارم، deadlines أصيلة، توليد كود للطرفين. − `ext-grpc` يطيل بناء صورة PHP (نخففه بطبقة Docker مخزنة/`install-php-extensions`).
