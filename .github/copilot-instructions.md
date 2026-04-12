# GitHub Copilot — Project Instructions: trading-platform-php (RUNTIME / WORK COPY)

> Loaded into every Copilot Agent session.
> Keep concise; store only durable project rules and decision guidance here.

## Repository Override: trading-platform-php (High Priority)
- This repository uses a runtime-first workflow.
- Primary edit target: `P:\opt\docker\trading-platform-php\`.
- Repository mirror for publication: `P:\GitHub\trading-platform-php\`.
- Do not perform direct feature edits in the GitHub mirror unless explicitly requested.
- After runtime validation, sync required files to the GitHub mirror and prepare commits there.
- For commit preparation, use `commit_prepare.py` (avoid ad-hoc manual staging/commit flow).
- If generic sections below conflict with this override, this override wins.
- `gitbash_exec` is optional and useful for bash-centric tasks (grep/sed/awk); use when it improves reliability or speed.

## Overview
- `sigsys-ts` is the TypeScript signals server / bot-control UI from the TradeBot system.
- Working tree: `P:\vps.alpet.me\sigsys-ts\`.
- Related PHP signals server: `P:\vps.alpet.me\sigsys\src\`.
- Publish repo: `P:\GitHub\TradeBot\`.

## Stack
- Frontend: Nuxt 3 SPA, Vue 3, Tailwind, `vue-i18n`, `tinycolor2`.
- Backend: NestJS, Passport JWT, TypeORM, PostgreSQL.
- Deploy: Docker Compose (`nginx`, `node`, `db`).
- Base path: `APP_BASE_PATH`, typically `/botctl`.

## Workflow
### MCP-first
- Prefer existing MCP / `cq_*` tools over generic shell work when they can do the same job with better observability, lower cost, or cached context.
- Typical MCP-first tasks: log search, file/index lookup, DB inspection, chat stats, patching, project selection, delegated chat orchestration.
- Use generic shell/code only for cross-cutting work, local runtime fixes, or gaps not covered by MCP.
- For cache/cost validation, prefer fast paid models such as `gpt5n` or `grok4f`; avoid free `nemotron` because it distorts telemetry.

### Assistant use
- Use helper LLMs for repetitive or judgment-heavy subtasks: review passes, log triage, code archaeology, measurements, structured synthesis.
- Keep the default agent focused on integration decisions, root-cause analysis, and final code changes.
- `shell_code` policy mostly concerns CQDS chat-based helpers, not this project-level instruction file.

### CQDS references
- `P:\opt\docker\cqds\docs\cached_interact.md` — cached interactive rollout plan and readiness metrics.
- `P:\opt\docker\cqds\docs\LLM-interactive-upgrade.md` — message/replication/MCP flow and known failure modes.
- `P:\opt\docker\docs\MCP_DELEGATION_QUICK_START.md` — concise execution checklist.
- `P:\opt\docker\docs\MCP_DELEGATION_STRATEGY.md` — when to use MCP chats, direct tools, or hybrid flow.
- `P:\opt\docker\docs\MCP_DELEGATION_TEMPLATE.md` — reusable root-message and sub-task templates.

### Decision rule
1. Existing MCP / `cq_*` tool
2. Specialized helper actor / assistant
3. Generic shell / ad-hoc code

### Repository & Commit Policy
- Treat `P:\GitHub\` as the canonical location for repositories.
- For commit preparation workflows, prefer using `commit_prepare.py` instead of ad-hoc git staging/commit commands.
- If working in a runtime mirror, sync changes to the corresponding `P:\GitHub\...` repository before final commit preparation.

## CQDS Integration & MCP Orchestration

### Your Role & Access

You are the **team-lead orchestrator** with full MCP tool access. You:
- Execute shell tasks, file inspection, DB queries directly via `cq_exec`, `cq_query_db`
- Rebuild indices, retrieve cached data via `cq_rebuild_index`, `cq_get_index`
- Coordinate async tasks to CQDS actors via `cq_send_message` in delegated chats
- Manage project context via `cq_list_projects`, `cq_select_project`
- Bridge local VS Code workspace with remote CQDS backend

### Tool Access Layers

**Copilot (you)**: Full MCP JSON schema — use `cq_*` tools for infrastructure tasks.

**CQDS Actors** (@gpt5n, @gpt5c, @grok4f, etc.): Sandwich XML format + `@agent` protocol only. **Cannot call `cq_*` functions**. They receive:
- Posts in reverse chronological order (tagged with `<post post_id="X">`)
- Files in sandwich tags (`<python/>`, `<bash/>`, `<sql/>`, etc.)
- JSON index with entity symbols and file metadata
- No visibility into MCP tools or model nicknames

### Decision Matrix: Direct vs Delegated

| Task | Approach | Rationale |
|------|----------|-----------|
| Shell command, log search, file inspection on CQDS host | `cq_exec` directly | Instant results, no actor overhead |
| Code generation, refactoring, architecture review | Delegate to Chat | Actors adapt pragmatically; leverage judgment-heavy LLM reasoning |
| Index refresh, cache queries | `cq_rebuild_index`, `cq_get_index` | Copilot-only tools; cost-effective |
| Multi-step coordination across tasks | `cq_send_message` chain | Async sequencing, avoid blocking |
| DB queries (SELECT) | `cq_query_db` directly | Fast, no actor data exposure needed |

### Best Practices for Actor Delegation

1. **Never mention `cq_*` tools in task messages** — actors cannot call them; will cause confusion and retry loops.
2. **Use `@attach` pattern** — pre-attach files in task message body; don't reference via "use `cq_get_index`".
3. **Document tool asymmetry** — clearly state in task: "You can use `@agent` to create files, `<replace>` for edits. Copilot will handle shell/DB tasks."
4. **Avoid runtime model nicknames** — base `llm_prepromt.md` stays generic; don't expose CQDS actor names to helpers.
5. **Read logs via `cq_exec`** — tail, grep, inspect only on host; actors never see raw logs.

### Example: Delegated Code Task

```
@gpt5c: Refactor the user auth flow in the attached middleware.

Context:
\x0F42  (entity: auth middleware class, file_id 42)

Request:
1. Extract JWT validation into standalone guard
2. Add conditional rate-limiting for failed attempts
3. Generate unit tests

You can use @agent to create files in backend/src/guards/. Copilot will run tests and merge.
```

(Does NOT say: "use cq_get_index", "use cq_exec", mentions no model names)

## Code Areas
- Backend core: `backend/src`.
- Important backend entry points: `app.module.ts`, `config/env.validation.ts`, `modules/*`.
- Frontend core: `frontend/pages`, `frontend/components`, `frontend/composables`.
- Admin SPA: `admin/`.
- Secrets and deploy config: `.envs/main/`, `docker-compose.yaml`, `docker/nginx/`.

## Conventions
- Auth flow: Telegram widget → `POST /api/user/telegram` → JWT signed with `JWT_SECRET`.
- Frontend stores token locally and sends `Authorization: Bearer <token>`.
- Protected routes must use `JwtAuthGuard`; never rely on `AdminGuard` alone.
- Admin rights come from `rights.includes('admin')` in `chat_users` via `UserExternalService`.
- For backend-to-backend HTTP calls, use `backendFetch()` / `safeFetch()` from `config/env.validation.ts`, never raw `fetch` directly.
- User rights source is controlled by `USERS_API_SOURCE` (`ts` or `php`).

## Style Baseline (Required)
- Minimum indentation in code blocks is **4 spaces**.
- Exception: top-level `<?php` and `?>` tags must stay at column 1 (no leading spaces).
- File encoding: **UTF-8**.
- Line endings: **LF**.
- Apply these rules to newly generated or modified project files by default.

## Required Environment
- `JWT_SECRET`
- `DATABASE_URL`
- `TRADING_DB_AUTH_URL`
- `BOT_TOKEN` or `TELEGRAM_BOT_TOKEN`
- `SIGNALS_API_URL`
- `APP_BASE_PATH`
- `DOMAIN`
- `TRADEBOT_PHP_HOST`
- `USERS_API_SOURCE`

## Security Rules
- Never add fallback secrets such as `|| 'secretKey'`.
- Never add string-based auth bypasses such as `NODE_ENV === 'true'`.
- New PHP code must use prepared statements, not interpolated SQL.
- PHP debug logging must stay gated by dev environment checks.
- Never commit real-token `*.http` files; `dev/` is gitignored for this reason.

## Code Index Access
- Do not embed file/entity indexes in this instruction file.
- Prefer MCP cached index access: `cq_get_index(project_id=...)`, `cq_rebuild_index(...)`.
- Use the VS Code task `Sandwich-pack: index project` only when a manual refresh is actually needed.
- Treat MCP/index responses as the authoritative current source because they are cheaper and keep the pre-prompt small.
