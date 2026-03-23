# Users API refactor plan (PHP -> TypeScript)

## Goal

Move only thin users API from PHP (`/api/users/*`) to TypeScript backend, keep `sig_edit.php` unchanged, simplify logic and keep strong logging without DB dependency.

## Current constraints

- Keep existing dynamic frontend behavior unchanged as much as possible.
- Do not migrate `sig_edit.php` in this stream.
- Keep rollback path to PHP until parity is proven.
- Logging must work even when database is unavailable.

## Done baseline changes

- Added `TRADING_DB_AUTH_URL` in:
  - `src/config/env.validation.ts`
  - `.env.example`
  - `README.md`

## Architecture decisions

1. **Single auth in TS backend**
   - Use existing JWT (`JwtAuthGuard`) as source of truth.
   - For users API in TS, no internal bearer handoff to PHP.

2. **Single source of users rights**
   - Read/write rights directly in `chat_users` (trading DB).
   - Keep current rights vocabulary: `view`, `trade`, `admin`.

3. **Feature-flag switch**
   - Add runtime switch:
     - `USERS_API_SOURCE=php` (current)
     - `USERS_API_SOURCE=ts` (new)
   - First implement TS path, then switch safely.

4. **DB-independent logging**
   - Request + audit logs are file-based JSONL + console fallback.
   - Logging failures must never break API response.

---

## Phase 1: Logging foundation (no behavior changes)

### Create files

- `src/common/logging/request-id.middleware.ts`
- `src/common/logging/audit-log.service.ts`
- `src/common/logging/logging.module.ts`

### Update files

- `src/main.ts` (register middleware)
- `src/app.module.ts` (import logging module)

### What to implement

- Generate `requestId` per request (header `X-Request-Id`).
- File logs:
  - `logs/users-api.log` for request/audit events
  - `logs/users-error.log` for failures
- Fallback to Nest `Logger` if file write fails.
- Redact sensitive values (tokens, raw auth headers).

### Tests to add

- `test/logging/request-id.middleware.spec.ts`
- `test/logging/audit-log.service.spec.ts`

### Verify commands (PowerShell)

```powershell
yarn test -- request-id.middleware.spec.ts
yarn test -- audit-log.service.spec.ts
```

---

## Phase 2: Trading DB users read/write layer

### Create files

- `src/modules/user/trading/trading-users.types.ts`
- `src/modules/user/trading/trading-users.repository.ts`
- `src/modules/user/trading/trading-users.module.ts`

### What to implement

Repository against `TRADING_DB_AUTH_URL`:
- `findAll(): Promise<User[]>`
- `findByChatId(id: number)`
- `findByUserName(userName: string)`
- `create(payload)`
- `updateRightsAndEnabled(id, rights, enabled)`
- `deleteByChatId(id)`

Behavior compatibility with PHP:
- Rights stored as CSV in DB, exposed as string array in API.
- `enabled` allowed values only `0|1`.

### Tests to add

- `src/modules/user/trading/trading-users.repository.spec.ts`

Mock DB adapter in tests (no real DB dependency).

### Verify commands

```powershell
yarn test -- trading-users.repository.spec.ts
```

---

## Phase 3: TS users service (business logic parity)

### Create files

- `src/modules/user/trading/users-rights.guard.ts`
- `src/modules/user/trading/users-rights.service.ts`
- `src/modules/user/trading/users-api.dto.ts`

### Update files

- `src/modules/user/external/user.external.service.ts`

### What to implement

In service, add two implementations:
- `phpUsersProvider` (current behavior)
- `tsUsersProvider` (new behavior via repository)

Switch by `USERS_API_SOURCE`:
- default `php` for safe rollout
- `ts` for progressive cutover

Business logic parity targets:
- get users list (id mapping `chat_id -> id`, rights split)
- create user
- update user rights/enabled
- delete user
- admin check for current JWT user

### Tests to add

- `src/modules/user/external/user.external.service.spec.ts`
- `src/modules/user/trading/users-rights.guard.spec.ts`

### Verify commands

```powershell
yarn test -- user.external.service.spec.ts
yarn test -- users-rights.guard.spec.ts
```

---

## Phase 4: Contract compatibility + error normalization

### Create files

- `src/modules/user/external/users-api.error.ts`
- `src/modules/user/external/users-api.mapper.ts`

### What to implement

- Normalize TS responses/errors to existing frontend expectations.
- Keep HTTP status semantics close to current behavior.
- Replace "already exists" workaround with explicit branch:
  - if user exists by `chat_id` or `user_name`, return stable response with reason field.

### Tests to add

- `src/modules/user/external/users-api.mapper.spec.ts`
- `test/e2e/users-api-compat.e2e-spec.ts`

### Verify commands

```powershell
yarn test -- users-api.mapper.spec.ts
yarn test:e2e -- users-api-compat.e2e-spec.ts
```

---

## Phase 5: Cutover and rollback safety

### What to implement

- Enable `USERS_API_SOURCE=ts` in staging.
- Monitor logs and compare outputs against previous behavior.
- Keep emergency rollback: set `USERS_API_SOURCE=php` and restart backend.

### Smoke checklist

1. Telegram login creates local user in Postgres.
2. Users list works from UI.
3. Create/update/delete users works from UI.
4. Admin checks remain correct.
5. Logs are present when DB is down.

### Verify commands

```powershell
yarn build
yarn test
yarn test:e2e
```

---

## Phase 6: System events and admin notifications (TypeScript)

Goal: replace critical `trade_event.php` notifications for users/auth flow with native TypeScript implementation.

### Create files

- `src/modules/events/trading-events.types.ts`
- `src/modules/events/trading-events.repository.ts`
- `src/modules/events/admin-notify.service.ts`
- `src/modules/events/events.module.ts`

### Update files

- `src/modules/user/user.service.ts`
- `src/modules/user/external/user.external.service.ts`

### What to implement

- Write events directly into trading DB table `events`.
- Resolve admin destination chat via `channels` (`channel -> chat_id`) or config fallback.
- Emit notifications on:
   - successful telegram login
   - local user auto-create
   - external user create/update/delete
- Always write local file audit (already implemented in Phase 1), even if DB insert fails.

### New env vars

- `TRADING_EVENTS_ENABLED=1`
- `TRADING_EVENTS_ENABLED=1`
- `TRADING_EVENTS_HOST=<optional_host_override>`

### Tests to add

- `src/modules/events/trading-events.repository.spec.ts`
- `src/modules/events/admin-notify.service.spec.ts`
- extend `src/modules/user/user.service.spec.ts` with notification assertions

### Verify commands

```powershell
yarn test -- trading-events.repository.spec.ts
yarn test -- admin-notify.service.spec.ts
```

---

## Simplification policy (important)

1. Keep old external routes untouched for frontend compatibility.
2. Avoid touching `sig_edit.php` and its flow.
3. Remove hidden side effects:
   - do not use created user id as admin checker header equivalent.
4. Do not add a third user source of truth.
5. Every logic change must be protected by tests before cutover.

---

## Execution mode for next steps

Implementation order for assistant:
1. Phase 1 code + tests
2. Run focused tests and share output
3. Phase 2 code + tests
4. Run focused tests and share output
5. Phase 3 code + tests
6. Run focused tests and share output
7. Then proceed to Phase 4/5

After each phase:
- provide changed files list
- provide exact PowerShell commands for your local verification
- do not proceed to next phase until current tests pass
