# CQDS Delegation Template (Copy & Adapt)

Use this template when delegating project tasks to LLM helpers in Colloquium-DevSpace chats.

## Phase A: Root Message (Pinned, No Model Tags)

```
PROJECT: [ProjectName]
TASK: [ConciseName]
SCOPE: [What's in, what's out]

CONTEXT:
- Project index: @attach#[ID_of_index_file]
- Key files: @attach#[ID1], @attach#[ID2], @attach#[ID3]
- Documentation: [Link to policy/checklist]

BOUNDARIES:
- File list limited to: [list names or patterns]
- Do NOT modify: [runtime configs, secrets, etc.]
- Time budget: [e.g., 10 minutes]
- Output format: [table/checklist/markdown/diagram]

POLICY/RULES:
- [Key rule 1 from project]
- [Key rule 2]
- Severity scale: critical | high | medium | low
- Anonymization: use [HOST_CORE], [ACCOUNT_A], not real values

EXPECTED OUTPUT:
1. [Specific table/list structure]
2. [Specific checklist format]
3. [Summary format]
```

## Phase B: Echo Test (Confirm Understanding)

```
@gpt5c @claude4o — you have read the root message above and understand:
1. Scope and boundaries?
2. Output format and rules?
Confirm with 1 line each, or ask for clarification.
```

Wait for responses. If any model asks for context → **do not proceed yet**. 
Adjust root message, ping model again.

## Phase C: Focused Sub-Tasks

Example 1 (Code Review Split):
```
@gpt5c: Analyze files [@attach#10, @attach#11] for security/logic issues.
Output: table (severity | file | issue | impact | fix).
Time: 5 min.

@claude4o: Use @gpt5c's findings. Rate confidence level for each finding.
Add checklist: "7-step review cycle for this codebase".
Time: 5 min.

@grok4f: Cross-check both outputs. Any false positives or missed patterns?
Output: bullet list (confirmed | hypothesis | false_positive).
```

Example 2 (Wiki Generation):
```
@claude4s: Create wiki structure (8-10 page titles + purposes + key sections).
Use only anonymized placeholders ([HOST_CORE], [SERVICE_API], etc).
Time: 5 min.

@gpt5c: Draft 3 markdown pages (Overview, Architecture, API Contracts).
Use @claude4s's structure.
Time: 10 min.

@claude4o: Generate 3 Mermaid diagrams (architecture, data flow, deploy cycle).
Validate syntax and anonymization.
```

Example 3 (Sanitization Review):
```
@gpt5c: Review files [@attach#X, @attach#Y] for leaked infrastructure/secrets.
Output: table (file | line | pattern | risk_level | fix).

@claude4o: Propose sanitization strategy (what to mask, what to keep).
Output: mapping table (sensitive_pattern → replacement).
```

## Phase D: Result Synthesis

```
RESULTS SUMMARY:
[Consolidate findings from all models]
[One markdown file or table combining inputs]
[Final status: what passed, what needs more work]
```

---

## Model Selection Guide (Based on Strengths)

| Model | Best For | Speed | Reliability |
|-------|----------|-------|-------------|
| `gpt5c` | Structure, checklists | Fast | High |
| `claude4s` | Creative docs, design | Slower | High |
| `gpt5n` | Pattern matching, grep-like | Very fast | Medium |
| `grok4f` | Cross-validation, factual | Fast | Medium |
| `claude4o` | Safety, edge cases | Slower | Very High |
| `nemotron3s` | Breadth analysis | Medium | Medium |

---

## Anti-Patterns (What NOT to Do)

1. ❌ **Dump entire project** → use @attach# indices only.
2. ❌ **Post same task to @all** → use selective tags + sub-tasks.
3. ❌ **Expect async consensus** → collect, synthesize once.
4. ❌ **No scope/boundaries** → models speculate, waste tokens.
5. ❌ **Tag models without prior wait** → root message should be stable first.
6. ❌ **Infinite back-and-forth** → set time budget, take what you get.

---

## Automation Hints

- **Bootstrap in code**: Create chat via `cq_create_chat`, post root message via `cq_send_message`, wait 3s.
- **Stagger parallel chats**: use `cq_send_message` with 2-3 min delays.
- **Sync mode for focused tasks**: `cq_set_sync_mode(timeout=60)` per chat, turn off after phase C.
- **Harvest results**: `cq_get_history` once status is free, parse JSON responses.

---

## Cost/Time Budgets (Optimized)

| Scenario | Cost (K tokens) | Time | Models | Notes |
|----------|-----------------|------|--------|-------|
| Code review (1 file) | 1-2 | 3 min | 2 | Focused, fast |
| Wiki structure (full) | 3-5 | 10 min | 3 | Creative, slower |
| Security audit (big codebase) | 5-8 | 15 min | 3-4 | Parallel advantage |
| Simple checklist | <1 | 2 min | 1 | Bootstrap only |

---

## Parking Lot: Issues to Resolve in Colloquium

1. **Sequential chat queue**: why does 1 busy chat block others?
2. **Context carryover**: models in new chat don't inherit indexed context.
3. **Model model list**: no canonical list of available models in deployment.
4. **@attach# syntax robustness**: should handle ranges, lists without parsing errors.
5. **Sync mode UX**: should have 3-tier (off, light, strict) instead of binary timeout.

