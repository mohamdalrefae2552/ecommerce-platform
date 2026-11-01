# ADR-0001: Modular Monolith في Laravel بأربع وحدات
**الحالة:** مقبول

## السياق
R1-S1/S2: مشروع واحد بأربع وحدات (Users, Products, Orders, Notifications)، ولا تقرأ وحدة جداول أخرى. المتطلبات اللاحقة (توسع، غير متزامن) قد تفصل وحدات لاحقاً.

## القرار
- `app/Modules/<Module>/` لكل وحدة بكل طبقاتها (HTTP، Actions، Models، Policies، Migrations، Provider، routes).
- الواجهة العامة لكل وحدة في `Contracts/` (interfaces + readonly DTOs + Events). أي استهلاك عبر الوحدات من هنا فقط.
- لا FK بين جداول وحدات مختلفة؛ مراجع ULID + لقطات بيانات.
- الفرض آلياً باختبارات Pest Arch في CI.
- `app/Shared/` للبنية العابرة (HTTP errors، Auth، InventoryGateway) ولا يعتمد على أي وحدة.

## البدائل المرفوضة
- **Microservices لكل وحدة:** تعقيد تشغيلي بلا مقابل في المتطلب 1، والعرض يطلب "مشروعاً واحداً".
- **حزم nwidart/laravel-modules:** طبقة سحرية إضافية؛ بنية المجلدات + Service Providers كافية ومفهومة.
- **تقسيم طبقي تقليدي (app/Models, app/Http):** لا يحقق الاستقلال المطلوب ويصعب إثباته.

## العواقب
+ حدود مُثبتة باختبار، قابلة للفصل لاحقاً. − بعض التكرار (DTOs) وعدم إمكان JOIN بين الوحدات (مقصود).
