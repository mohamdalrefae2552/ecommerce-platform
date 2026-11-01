---
name: contract-first
description: تعديل عقد REST (contracts/openapi) أو gRPC (contracts/proto) بأمان. استخدمه عند أي طلب [CONTRACT-REQUEST] أو قبل إضافة endpoint/حقل/RPC. للـ Lead فقط.
when_to_use: "عند طلب تغيير العقد، إضافة endpoint، إضافة حقل للرد، تعديل proto، أو استلام رسالة CONTRACT-REQUEST."
---

# تغيير العقد (Lead فقط)

1. **قيّم الطلب:** هل يخدم متطلباً في `docs/00`؟ هل هو إضافة (آمن) أم كسر (ممنوع في v1)؟ الكسر ⇒ ارفض أو اسأل الإنسان.
2. **عدّل المصدر:**
   - REST: `contracts/openapi/openapi.yaml` + حدّث `docs/02` إن تغيرت قاعدة أو كتالوج الأخطاء `contracts/errors.md`.
   - gRPC: `contracts/proto/inventory/v1/inventory.proto` بتعليق لكل حقل جديد؛ رقم حقل جديد لا يُعاد استخدامه أبداً (`reserved` للمحذوف).
3. **تحقق:** `make lint-contracts` (Redocly + `buf lint` + `buf breaking --against origin/main`). يجب أن يمر بلا أي تحذير.
4. **ولّد:** `make gen` ⇒ تأكد أن `backend/api/generated/` و `frontend/src/api/schema.d.ts` تغيّرا كما متوقع فقط.
5. **commit** على فرع `chore/pN-contracts-<topic>` ⇒ PR صغير منفصل ⇒ دمج **قبل** أن يبني عليه أي وكيل.
6. **أعلن** لكل وكيل متأثر برسالة:
   ```
   [CONTRACT-CHANGED] <ملخص سطر واحد>
   files: …  · merged in: <sha>
   action: git fetch && git rebase origin/main && make gen
   ```
