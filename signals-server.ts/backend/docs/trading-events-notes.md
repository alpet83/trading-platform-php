# Trading DB + trade_event notes

Date: 2026-03-22

## Extracted schema facts (from `trading_proto.sql`)

## `chat_users`
- `chat_id` bigint unsigned, PK
- `user_name` unique
- `enabled` int default `1`
- `rights` varchar(48) default `''`
- service fields: `last_cmd`, `last_msg`, `last_notify`, `auth_pass`

Impact:
- our TS repository mapping for users is correct (`chat_id -> id`, rights CSV, enabled 0/1)
- there are extra service fields that should remain untouched by users API refactor

## `events`
- `id` autoincrement PK
- `host` int (indexed)
- `tag` varchar(8)
- `event` varchar(2048)
- `value` double
- `flags` int
- `ts` timestamp default current_timestamp
- `attach` mediumblob
- `chat` bigint

Impact:
- natural target table for TypeScript event registration
- suitable for admin/system notifications pipeline

## `signals`
- `id`, `signal_no`, `setup`, `pair_id`, `buy`, `source_ip`, `mult`, prices, flags, comment
- unique key `trade_no(signal_no, pair_id, setup)`

Impact:
- confirms `sig_edit.php` scope is rich and should remain out of users-only migration

## `channels`
- `chat_id` PK
- `channel` unique
- used as map from symbolic channel to chat id

Impact:
- useful for resolving admin/system destination chat in notifications

---

## Current `trade_event.php` behavior

File: `trade_event.php`

- Accepts params: `tag`, `event`, `host`, `value`, `flags`, `attach`, `channel`
- Resolves `chat_id` via `channels` table (`channel -> chat_id`)
- Resolves host id via `hosts` table (`name/ip -> id`, fallback `9`)
- Decodes optional `attach` from base64
- Writes into `events` with SQL:
  - `INSERT IGNORE INTO events (tag, host, event, value, flags, attach, chat)`
- Logs to files:
  - `/var/log/trade_event.log`
  - `/var/log/attach.log`

Notable:
- works independently from backend Postgres
- can be used as source of truth for system-level notifications

---

## TypeScript hook points for admin notifications

Primary hook points found:
- `src/modules/user/user.service.ts`
  - `loginWithTelegram(...)`
  - `createUser(...)`
- `src/modules/user/external/user.external.service.ts`
  - create/update/delete users operations

Meaning:
- we can emit system events at login/create/update/delete without touching `sig_edit.php`

---

## Proposed TypeScript event integration (next)

1. Add event repository for `events` table in trading DB.
2. Add notification service that:
   - writes event row into `events`
   - optionally sends direct Telegram message to admin chat(s)
   - always logs to file via `AuditLogService` fallback
3. Trigger notifications from:
   - successful telegram login
   - user auto-create
   - external user create/update/delete actions
4. Keep feature flag:
   - `TRADING_EVENTS_ENABLED=0|1`
   - default `1` in prod, can disable fast if needed

---

## Minimal event payload recommendation

- `tag`: use processor-supported types, currently `LOGIN` for auth success and `REPORT` for user-management notifications
- `event`: readable message (`User created`, `Admin login`, ...)
- `value`: numeric (`0` default)
- `flags`: bitmask (`0` default)
- `host`: resolved from config/ip
- `chat`: admin chat id or resolved by channel (`admin`)
- `attach`: optional JSON metadata (base64 or plain JSON in TS layer)
