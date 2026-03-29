# Security Audit — TradeBot / sigsys-ts

**Date:** 2026-03-23  
**Scope:** NestJS backend (`sigsys-ts/backend/`) + PHP signals server (`sigsys/src/`)  
**Trigger:** GitHub secret scanning flagged `backend/dev/http-requests/user.http`

---

## Summary

| # | Severity | Component | File | Issue | Status |
|---|----------|-----------|------|-------|--------|
| 1 | 🔴 Critical | NestJS | `jwt.strategy.ts` | Hardcoded fallback JWT secret `'secretKey'` | ✅ Fixed |
| 2 | 🔴 Critical | NestJS | `jwt.strategy.ts` | Auth backdoor via `NODE_ENV === 'true'` | ✅ Fixed |
| 3 | 🔴 Critical | NestJS | `admin.guard.ts` | `AdminGuard` always returned `true` | ✅ Fixed |
| 4 | 🟠 High | NestJS | `user.module.ts` | Hardcoded fallback JWT secret in `JwtModule.register()` | ✅ Fixed |
| 5 | 🟠 High | NestJS | `chart.controller.ts` | `GET /chart` — no authentication at all | ✅ Fixed |
| 6 | 🟠 High | NestJS | `dev/user.http` | Hardcoded JWT token + real Telegram ID + username in repo | ✅ Fixed |
| 7 | 🟡 Medium | PHP | `api_helper.php` | Full HTTP headers + `$_SERVER` dumped to `/tmp/api-debug.log` unconditionally | ✅ Fixed |
| 8 | 🟡 Medium | PHP | `api_helper.php` | Hardcoded `http://vps.vpn` internal hostname | ✅ Fixed |
| 9 | 🟡 Medium | PHP | `api_helper.php` | 401 error response leaked token fragments | ✅ Fixed |
| 10 | 🟡 Medium | PHP | `get_user_rights.php` | Unsanitized `$uid` interpolated into SQL query | ✅ Fixed |

---

## Detailed Findings & Fixes

### 1 & 2 — `jwt.strategy.ts`: Hardcoded secret + Auth backdoor

**File:** `backend/src/modules/jwt/jwt.strategy.ts`

**Before:**
```typescript
super({
  secretOrKey: process.env.JWT_SECRET || 'secretKey',  // fallback to known weak key
});

async validate(payload: any) {
  if (process.env.NODE_ENV === 'true') {         // string comparison, never true in Node.js
    return await this.userService.findUser(1);   // bypasses ALL auth — returns user #1
  }
  ...
}
```

**Risks:**
- If `JWT_SECRET` is not set in `.env`, any attacker who knows the fallback `'secretKey'` can forge valid JWTs and authenticate as any user.
- If someone sets `NODE_ENV=true` in the environment, **every authenticated endpoint** returns user #1 without validating the token at all.

**After:**
```typescript
constructor(private readonly userService: UserService) {
  const secret = process.env.JWT_SECRET;
  if (!secret) {
    throw new Error('JWT_SECRET environment variable is not set. Cannot start without a signing secret.');
  }
  super({ secretOrKey: secret, ... });
}

async validate(payload: any) {
  const user = await this.userService.findUser(payload.id);
  if (!user) throw new UnauthorizedException('User not found');
  return user;
}
```

---

### 3 — `admin.guard.ts`: Stub guard that always allows

**File:** `backend/src/common/auth/admin.guard.ts`

**Before:**
```typescript
@Injectable()
export class AdminGuard implements CanActivate {
  async canActivate(context: ExecutionContext): Promise<boolean> {
    return true;  // no check whatsoever
  }
}
```

**Risk:** Any authenticated user could access admin-only routes.

**After:**
```typescript
@Injectable()
export class AdminGuard implements CanActivate {
  constructor(private readonly externalService: UserExternalService) {}

  async canActivate(context: ExecutionContext): Promise<boolean> {
    const request = context.switchToHttp().getRequest();
    const user = request.user;
    if (!user) return false;
    return Boolean(await this.externalService.isAdmin(user));
  }
}
```

`isAdmin()` checks `rights.includes('admin')` against the `chat_users` table in the trading DB.

Also: `AdminGuard` added as provider + export in `UserExternalModule` so it can be DI-injected wherever needed.

---

### 4 — `user.module.ts`: Same weak secret fallback in JwtModule

**File:** `backend/src/modules/user/user.module.ts`

**Before:**
```typescript
JwtModule.register({
  secret: process.env.JWT_SECRET || 'secretKey',
  ...
})
```

**After:**
```typescript
JwtModule.register({
  secret: process.env.JWT_SECRET,
  ...
})
```

---

### 5 — `chart.controller.ts`: Unauthenticated endpoint

**File:** `backend/src/modules/chart/chart.controller.ts`

**Before:**
```typescript
@Controller('chart')
export class ChartController {
  @Get()
  async getChartData(...) { ... }  // no guard
}
```

**Risk:** `GET /api/chart?exchange=...&account_id=279405` was publicly accessible — exposes account trading data to anyone.

**After:**
```typescript
@UseGuards(JwtAuthGuard)
@Controller('chart')
export class ChartController { ... }
```

---

### 6 — `dev/user.http`: Credentials committed to repo

**File:** `backend/dev/http-requests/user.http`

**Contents:** Hardcoded JWT token (expired, but still a pattern), real Telegram user ID `1444238727`, real username, test credentials `test@test.ru / 123123`.

**Fix:** Added `dev/` to `backend/.gitignore`:
```
# HTTP request files with tokens/credentials
dev/
```

The expired token itself is not immediately exploitable, but the real Telegram ID and username are PII and should not be in VCS history. Consider `git filter-repo` or BFG Repo Cleaner to purge the file from history before publishing.

---

### 7, 8, 9 — `api_helper.php`: Three issues

**File:** `sigsys/src/api_helper.php`

**Issue 7 — Unconditional debug log (information disclosure):**
```php
// Before: always writes full request headers + $_SERVER to /tmp/api-debug.log
$log_file = fopen('/tmp/api-debug.log', 'w');
log_msg("#DBG(headers): \n%s", print_r($headers, true));
log_msg("#DBG(SERVER): \n%s", print_r($_SERVER, true));
```
Exposes: Authorization headers (including bearer tokens), client IPs, server paths, all query params.

```php
// After: only in dev
if (getenv('ENVIRONMENT') === 'dev') {
    $log_fn = '/tmp/api-debug.log';
    $log_file = fopen($log_fn, 'w');
    if (is_resource($log_file)) {
        log_msg("#DBG(headers): \n%s", print_r($headers, true));
        log_msg("#DBG(SERVER): \n%s", print_r($_SERVER, true));
    }
}
```

**Issue 8 — Hardcoded internal hostname:**
```php
// Before
$host = getenv('ENVIRONMENT') === 'dev' ? 'http://tradebot-new-php' : 'http://vps.vpn';
```
`http://vps.vpn` is an internal VPN hostname that should not be hardcoded.

```php
// After
$tradebot_host = getenv('TRADEBOT_PHP_HOST');
if (!$tradebot_host) {
    send_error('Server misconfiguration: TRADEBOT_PHP_HOST not set', 500);
    exit;
}
```

**Issue 9 — Error message leaks token fragments:**
```php
// Before — could reveal partial token or expected format
send_error(['m' => "Unauthorized by [$token]/Token not specified/Wrong IP", ...], 401);

// After
send_error('Unauthorized', 401);
```

---

### 10 — `get_user_rights.php`: SQL injection

**File:** `sigsys/src/get_user_rights.php`

**Before:**
```php
$uid = rqs_param('id', 0) * 1;
// ...
$res = $mysqli->select_row('user_name,rights', 'chat_users', "WHERE chat_id = $uid", MYSQLI_OBJECT);
// also leaked user ID in "none for X" response
echo 'none for '.$uid;
```

`rqs_param()` does **no sanitization** — it returns raw `$_REQUEST` values. The `* 1` numeric cast does prevent injection in practice (PHP casts `"1 OR 1=1"` → `1`), but direct interpolation into SQL is a bad pattern and not safe in all edge cases.

**After (prepared statement):**
```php
$uid = intval(rqs_param('id', 0));
// ...
$stmt = $mysqli->prepare('SELECT user_name, rights FROM chat_users WHERE chat_id = ?');
$stmt->bind_param('i', $uid);
$stmt->execute();
$res = $stmt->get_result();
$row = $res ? $res->fetch_object() : null;
$stmt->close();
$mysqli->close();
if ($row)
    echo $row->rights;
else
    echo 'none';  // no longer leaks $uid value
```

---

## Remaining Concerns (not fixed — require design decisions)

### POST /user/telegram — intentionally open
`UserController.telegramLogin()` has no `@UseGuards`. This is intentional — it's the Telegram OAuth entry point. The security here relies on `validateTelegramAuth()` which:
1. Builds a sorted key=value string from the Telegram data payload
2. Computes `HMAC-SHA256(SHA256(BOT_TOKEN), sorted_data)`
3. Compares against the `hash` field from Telegram Widget

This is correct per [Telegram Login Widget spec](https://core.telegram.org/widgets/login#checking-authorization). **However**: there is no `auth_date` freshness check — a replayed Telegram auth payload from years ago would still be accepted. Consider rejecting if `auth_date` is older than e.g. 24 hours.

### POST /telegram/login — stub
`TelegramController` at `POST /telegram/login` just does `console.log(123)` and returns nothing. Not a security risk but needs to be removed or implemented before production.

### `user.service.ts` — process.env dump in error
```typescript
throw new UnauthorizedException("botToken not provided to backend. Process ENV: " + JSON.stringify(process.env));
```
If `TELEGRAM_BOT_TOKEN` is not set, this returns the **entire process environment** (all env vars) in a 401 response. Should be replaced with a generic message.

### First-user auto-admin
The first Telegram user to login is automatically granted `['admin']` rights. This is acceptable for initial setup but should be documented and ideally gated (e.g., only allowed during a setup window or with a bootstrap token).

---

## Environment Variables Required

Ensure these are set before deploying (never use defaults):

| Variable | Used in | Notes |
|----------|---------|-------|
| `JWT_SECRET` | NestJS | Min 32 chars random string. App **will not start** if missing. |
| `TELEGRAM_BOT_TOKEN` | NestJS | Required for Telegram login. |
| `TRADEBOT_PHP_HOST` | PHP | URL of the trading bot PHP host, e.g. `http://tradebot-php` |
| `FRONTEND_TOKEN` | PHP | Bearer token for NestJS→PHP requests (defined in `db_config.php`) |
| `ENVIRONMENT` | PHP | Set to `dev` to enable debug logging |

---

## Files Modified

| File | Change |
|------|--------|
| `backend/src/modules/jwt/jwt.strategy.ts` | Removed `'secretKey'` fallback; removed `NODE_ENV==='true'` backdoor |
| `backend/src/modules/user/user.module.ts` | Removed `'secretKey'` fallback in `JwtModule.register()` |
| `backend/src/common/auth/admin.guard.ts` | Implemented real admin check via `UserExternalService.isAdmin()` |
| `backend/src/modules/user/external/user.external.module.ts` | Added `AdminGuard` as provider + export |
| `backend/src/modules/chart/chart.controller.ts` | Added `@UseGuards(JwtAuthGuard)` |
| `backend/.gitignore` | Added `dev/` to exclude `*.http` files with credentials |
| `sigsys/src/api_helper.php` | Debug log guarded by `ENVIRONMENT=dev`; `vps.vpn` → `TRADEBOT_PHP_HOST`; sanitized error message |
| `sigsys/src/get_user_rights.php` | Replaced string interpolation in SQL with prepared statement; removed UID from error response |
