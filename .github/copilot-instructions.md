# GitHub Copilot — Project Instructions: sigsys-ts

> Loaded into every Copilot Agent session.
> Keep concise; store only durable project rules and decision guidance here.

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

### Shell execution on Windows
- For Linux-oriented project scripts (`.sh`, GNU coreutils flows, bash substitutions), prefer `mcp_gitbash_gitbash_exec` over PowerShell shell execution.
- Use PowerShell shell execution for native Windows-only tasks and for direct interaction with Windows tooling.

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
