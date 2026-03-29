# API usage map: sigsys-ts -> sigsys

Date: 2026-03-22

## 1) Locations

- PHP API (MariaDB via db_tools): `p:\vps.alpet.me\sigsys\src`
- TS backend (NestJS proxy to PHP API): `p:\vps.alpet.me\sigsys-ts\backend\src`

PHP endpoints implemented by `index.php`:
- `src/api/users/index.php`
- `src/api/users/create/index.php`
- `src/api/users/update/index.php`
- `src/api/users/delete/index.php`

TS wrappers:
- `src/modules/user/external/user.external.controller.ts`
- `src/modules/user/external/user.external.service.ts`

## 2) Auth and rights flow

- `api_helper.php` runs `check_auth()` for all `api/users/*`.
- Required header: `Authorization: Bearer <AUTH_TOKEN>`; otherwise HTTP `401`.
- TS takes token from `Env.AUTH_TOKEN` in `config/env.validation.ts`.
- Rights are resolved via header `X-User-Id` -> `get_user_rights.php?id=<telegram_id>`.
- `get_user_rights.php` reads `chat_users.rights` and returns rights string.
- All `api/users/*` require `admin` right, otherwise HTTP `403`.

## 3) Endpoint mapping (TS -> PHP -> DB)

### 3.1 List users
- TS route: `GET /external/user`
- TS call: `fetch(Env.SIGNALS_API_URL + '/api/users/', { method: 'GET' })`
- Headers: `Authorization`, `X-User-Id: req.user.telegramId`
- PHP file: `src/api/users/index.php`
- PHP method: `GET` only
- SQL: `SELECT chat_id, user_name, rights, enabled FROM chat_users`
- Response transform: `chat_id -> id`, `rights CSV -> string[]`
- db_tools usage: `init_remote_db('trading')`, `$mysqli->query(...)`

### 3.2 Create user
- TS route: `POST /external/user`
- TS call: `fetch(... '/api/users/create', { method: 'POST' })`
- Body (`x-www-form-urlencoded`): `user_name`, `id`, `enabled`, `rights[]`
- Headers: `Authorization`, `X-User-Id: proto.id`
- PHP file: `src/api/users/create/index.php`
- PHP method: `POST` only
- Required fields: `id`, `user_name`
- Optional fields: `rights`, `enabled` (default `1`)
- Validation: `id > 0`, non-empty `user_name`, `rights in [view,trade,admin]`, `enabled in [0,1]`
- Duplicate check: by `chat_id` OR `user_name`
- Existing user behavior: returns HTTP `201` with `{ success: true, user: ... }` (no insert)
- Insert SQL: `INSERT INTO chat_users (chat_id, user_name, rights, enabled) ...`
- db_tools usage: `init_remote_db('trading')`, `select_value(...)`, `try_query(...)`

### 3.3 Update user
- TS route: `POST /external/user/update`
- TS call: `fetch(... '/api/users/update', { method: 'POST' })`
- Body: `user_name`, `id`, `enabled`, `rights[]`
- Headers: `Authorization`, `X-User-Id: req.user.telegramId`
- PHP file: `src/api/users/update/index.php`
- PHP method: `POST` only
- Required fields: `id`, `rights`, `enabled`
- Note: `user_name` is sent by TS but not used in PHP SQL update
- SQL: `UPDATE chat_users SET rights = ..., enabled = ... WHERE chat_id = ...`
- db_tools usage: `init_remote_db('trading')`, `select_row(...)`, `try_query(...)`

### 3.4 Delete user
- TS route: `DELETE /external/user/:id`
- TS call: `fetch(... '/api/users/delete', { method: 'POST' })`
- Body: `id`
- Headers: `Authorization`, `X-User-Id: req.user.telegramId`
- PHP file: `src/api/users/delete/index.php`
- PHP method: `POST` only
- SQL: `DELETE FROM chat_users WHERE chat_id = ...`
- db_tools usage: `init_remote_db('trading')`, `select_row(...)`, `try_query(...)`

## 4) NestJS route chain

Controller `user.external.controller.ts` exposes:
- `GET /external/user` -> `UserExternalService.getUsers()` -> PHP `/api/users/`
- `GET /isAdmin` -> `UserExternalService.isAdmin()` -> PHP `/api/users/`
- `POST /external/user` -> `createUser()` -> PHP `/api/users/create`
- `POST /external/user/update` -> `updateUser()` -> PHP `/api/users/update`
- `DELETE /external/user/:id` -> `deleteUser()` -> PHP `/api/users/delete` (POST on PHP side)

## 5) Important notes

1. `api/users/*` fully depends on MariaDB table `chat_users` in DB `trading`.
2. Access requires BOTH valid Bearer token and `admin` right for `X-User-Id`.
3. Potential mismatch in TS create flow:
   - `createUser()` sends `X-User-Id = proto.id` (created user id), not current admin id.
   - This can produce HTTP `403` when created user is not admin.
4. In `UserService.createUser(...)`, call passes `{ telegramId: 1 }` as second arg,
   but `UserExternalService.createUser()` ignores this value for headers.
5. `deleteUser()` in TS returns raw `fetch` response object, unlike other methods that parse JSON.
