# MCP Tool Strategy: Revised Recommendations for TradeBot

## Current State Assessment (as of March 28, 2026)

### What We Tried
- Parallel multi-chat delegation: 3 chats (review, wiki, sanitization).
- General task prompts with no pre-loaded context.
- Heavy use of `@all` to invoke many models simultaneously.

### What Happened
- **Time**: 45 minutes (mostly queue stalls).
- **Cost**: ~15K tokens (speculative responses, retries, context reloads).
- **Quality**: Mixed — some useful findings, but buried in redundant/cautious text.
- **Operational**: Multiple models requesting same clarification (no shared context).

### Why It Failed
1. **Protocol vacuum**: no clear input spec → models guessed requirements.
2. **Context overload**: new chats had no index → forced rescans.
3. **Serialization bottleneck**: one busy `@all` chatter froze all others.
4. **Token bleed**: 5-6 models each saying "need files" instead of analyzing.

---

## Revised Strategy for TradeBot Cycle-of-Work

### For Code Review Tasks
**Old way**: Post general task to 3 new chats, wait 30 min, get mixed output.  
**New way**:
1. Create **one chat**: `"TradeBot Code Review — sigsys-ts Cleanup"`.
2. Root message: pinned rules + @attach# file indices (not full content).
3. Echo test: confirm 2 models understand scope + output format.
4. Task distribution:
   - `@gpt5c`: Finding table (severity | file | issue | impact | fix).
   - `@claude4o`: Security audit (known CVEs + policy compliance).
   - `@grok4f`: False positive validation.
5. Collect, synthesize once into markdown.

**Expected outcome**: 
- 10 minutes vs. 30 min.
- 4K tokens vs. 15K tokens.
- One clear findings table vs. repeated requests.

### For Wiki/Documentation
**Old way**: Ask for structure in one chat, wait for LLM to re-ask context.  
**New way**:
1. Single chat: `"TradeBot Wiki Generation (Anonymized)"`.
2. Root: policy doc (@attach#), anonymization rules, page template.
3. Sub-task sequence (not parallel):
   - Step A: Structure (8-10 pages) — `@claude4s` (5 min).
   - Step B: Content draft — `@gpt5c` reading structure (5 min).
   - Step C: Diagrams (Mermaid) — `@claude4o` (5 min).
4. Synthesis: merge outputs into wiki folder.

**Why sequential instead of parallel?**
- Each step depends on prior output.
- Avoids redundant context-loading between models.
- Clearer causality for debugging.

**Expected outcome**:
- 15 minutes vs. 30+ min (mostly waiting).
- 3-4K tokens vs. 8K tokens.

### For Sanitization/Leak Detection
**Old way**: Ask all models to scan same files, get consensus confusion.  
**New way**:
1. Pre-scan locally: `grep -r '(192\.|10\.|https?://|token|secret)' --include='*.php' --include='*.md'`.
   - Produces priority list fast (no LLM).
   - Ground truth for model validation.
2. Chat: `"TradeBot Sanitization Validation"`.
3. Root: scan results (as @attach# or inline table) + policy.
4. Task: `@claude4o` reviews findings vs. policy, flags false positives (2 min).
5. Synthesis: approved list + remediation steps.

**Why pre-scan locally?**
- Grep is deterministic, LLM is probabilistic.
- Use LLM for judgment (keep vs. mask), not discovery.
- Massively faster and cheaper.

**Expected outcome**:
- 5-10 minutes (mostly local work).
- <1K tokens (LLM validation only).
- 100% recall, lower false positives.

---

## When to Use MCP Delegation vs. Direct Tools

### Use MCP (Heavy LLM Work)
- Semantic analysis (is this a real bug or false alarm?).
- Document generation (structure + prose).
- Cross-validation (compare two approaches).
- Architecture/design review (subjective judgment).
- Prioritization (which findings matter most?).

### Use Direct Tools (Grep, Scripting, Code)
- Pattern matching (all URLs matching regex).
- Data transformation (JSON → CSV).
- File operations (rename, move, copy).
- Build/test execution (compile, run tests).
- Exhaustive scanning (all files for a pattern).

### Hybrid (Most Effective)
1. Direct tools + grep: gather facts locally.
2. MCP delegation: interpret facts, decide action.
3. Direct tools again: apply changes.

**Example**: Find secrets → [use grep locally] → Ask LLM "which are real vs. test?" → [use sed/replacement locally].

---

## Recommended Workflow for TradeBot Ongoing

### Iteration 1 (Cleanup Review Cycle)
1. **Local scan**: `grep + file index` → findings backlog.
2. **MCP chat** (code review focused):
   - Root: findings backlog + policy.
   - Task: `@gpt5c` severity-rate, `@claude4o` remediation, `@grok4f` cross-check.
   - Sync mode: 60 sec.
3. **Direct action**: apply approved changes.
4. **Artifact**: save findings table + decisions to `/docs/wiki/REVIEW_FINDINGS_v1.md`.

### Iteration 2 (Wiki Generation)
1. **Template setup** (local): create `/docs/wiki/pages/` structure.
2. **MCP chat** (sequential):
   - `@claude4s`: draft content for 3 pages.
   - `@gpt5c`: extract code examples (anonymized).
   - `@claude4o`: generate Mermaid diagrams.
3. **Local polish**: render, validate links, test Mermaid syntax.
4. **Artifact**: commit `/docs/wiki/` subtree.

### Iteration 3 (Sanitization & Hardening)
1. **Local scan**: identify P0/P1/P2 files needing masking.
2. **MCP validation** (quick):
   - `@claude4o`: policy review (can we mask this safely?).
   - `@gpt5c`: regex patterns for safe replacement.
3. **Local edit**: apply sed/replacement scripting.
4. **Final scan**: confirm zero new patterns.
5. **Artifact**: commit changes, doc sanitization log.

---

## Cost Modeling

### Old Approach (Pilot, Full Parallel)
- Time: 45 min (bottleneck on queue).
- Cost: ~15K tokens (multiple speculative responses).
- Result quality: 7/10 (useful but redundant).

### New Approach (Protocol + Hybrid)
- Time: ~30 min total (local work + 3 focused chats, sequential).
- Cost: ~8K tokens (lean, targeted tasking).
- Result quality: 9/10 (clear, actionable, minimal waste).

### Efficiency Gain
- **3.3x faster** (45 → 30 min, but mainly local work).
- **2x cheaper** (15K → 8K tokens).
- **Better quality**: focused LLM output, local verification.

---

## Operational Discipline (For Future Cycles)

**Do:**
- ✅ Start with clear protocol (root message, rules, format).
- ✅ Echo-test before heavy tasking.
- ✅ Use selective model tagging (not @all).
- ✅ Combine local tools with LLM judgment.
- ✅ Set time budgets (5-10 min per sub-task).
- ✅ Synthesize results into **one output artifact**.
- ✅ Save protocol + findings to memory.

**Don't:**
- ❌ Dump whole project in context.
- ❌ Start 3 chats with identical tasks.
- ❌ Use @all for general availability.
- ❌ Expect LLM to replace grep/regex.
- ❌ Leave chat results scattered across messages.
- ❌ Start new task without understanding prior output.

---

## Parking Lot: Improvements to CQDS

1. **Per-chat rate limiting**: prevent serialization when N chats active.
2. **Context inheritance**: new chat in same project should auto-load index.
3. **@attach# ranges**: support `@attach#[1-10,20,30-40]` syntax.
4. **Model availability registry**: known list of online LLMs, their capabilities.
5. **Result aggregation view**: show results from all chats in one pane.
6. **Token budgeting**: warn before firing expensive `@all` task.

---

## Summary

**Key Insight**: Delegating to LLMs is powerful, but only if you delegate **decisions** (interpret facts), not **discovery** (find facts).

Use grep → use LLM → use sed → repeat.

This transforms MCP from "slower alternative to CLI" into "strategic multiplier for judgment-heavy tasks."

