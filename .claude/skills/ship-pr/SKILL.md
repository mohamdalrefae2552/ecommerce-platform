---
name: ship-pr
description: تحضير فرع لطلب دمج — مراجعة مستقلة، وصف PR من القالب، وأوامر الدفع للعضو البشري المالك. لا يدفع ولا يفتح PR ولا يدمج بنفسه.
disable-model-invocation: true
argument-hint: "<worktree-path> <task-ids>"
---

# تحضير PR

المدخل: `$ARGUMENTS`

1. في الـ worktree: `git fetch && git rebase origin/main` ثم skill `verify` للمكوّن. يجب أن يكون أخضر.
2. استدعِ subagent `code-reviewer` على الفرع. أي CRITICAL/HIGH ⇒ أعد للمالك، لا تكمل.
3. نظّف التاريخ: commits منطقية بصيغة Conventional Commits (لا "fix typo" متراكمة — `git rebase` غير تفاعلي عبر `--autosquash` إن استُخدمت `fixup!`).
4. اكتب الوصف في `<worktree>/.git-pr-body.md` (غير مُلتزم) من `.github/pull_request_template.md`:
   - المهام المغطاة وروابط ملفاتها، المتطلبات (R1-*/R2-*/PROOF-*)
   - ما تغيّر ولماذا (القرارات + ADR إن وُجد)
   - كيف تتحقق (أوامر دقيقة)
   - نتيجة `code-reviewer` و `verify`
   - **نقاط على المراجع البشري فهمها** (3–5 نقاط — سيُسأل عنها في المقابلة)
5. اطبع للإنسان الأوامر ليشغّلها **العضو المالك** من حسابه:
   ```bash
   git -C <wt> push -u origin <branch>
   gh pr create --base main --head <branch> --title "<conventional title>" --body-file <wt>/.git-pr-body.md --reviewer <other-member-gh>
   ```
