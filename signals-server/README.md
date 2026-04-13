# signals-server (PHP backend)

This directory contains the **PHP signals backend** — a standalone server that stores
and manages trading signals, handles Telegram bot commands, and exposes a JSON API
consumed by the NestJS overlay (`signals-server-ts`).

> **This component must be deployed separately** before the NestJS/Nuxt stack can
> function. The NestJS backend proxies all signal reads and writes to this PHP server.

## Requirements

- PHP 8.1+ with extensions: `php-curl`, `php-mbstring`, `php-mysqli`
- MariaDB 10.11+ (or MySQL 8.0+)
- Apache 2.4 with `mod_rewrite` enabled
- A `db_config.php` configuration file (see below)

## Files

| File | Description |
|---|---|
| `sig_edit.php` | **Main signals API** — CRUD for trading signals (`?format=json`) |
| `get_signals.php` | Raw signal row dump (flat DB rows, not consumed by NestJS) |
| `get_user_rights.php` | Returns privilege flags for a given Telegram user ID |
| `api_helper.php` | Auth middleware (`check_auth()`, `get_user_rights()`) |
| `grid_edit.php` | Grid/position editing API |
| `lastpos.php` | Last known positions endpoint |
| `pairs_map.php` | Trading pair mapping |
| `trade_ctrl_bot.php` | Telegram bot command handler (long-polling daemon) |
| `trade_ctrl_bot.sh` | Shell wrapper to run the bot daemon with auto-restart |
| `trade_event.php` | Receives trade events from the trading engine |
| `trading_proto.sql` | Database schema prototype |
| `docs.yaml` | OpenAPI spec for the signals API |
| `api/users/` | REST endpoints for user management (create/update/delete) |

## Database setup

Import the schema prototype to create the required tables:

```bash
mysql -u root -p sigsys < trading_proto.sql
```

The database name is `sigsys` by default. Adjust in `db_config.php` if needed.

## Configuration

Create `/usr/local/etc/php/db_config.php` (path is configurable in `api_helper.php`):

```php
<?php
define('MYSQL_USER',      'your_db_user');
define('MYSQL_PASSWORD',  'your_db_password');
define('FRONTEND_TOKEN',  'your_secret_token');   // Must match AUTH_TOKEN in NestJS
define('TELEGRAM_API_KEY','your_telegram_bot_token');
```

> `FRONTEND_TOKEN` is the shared secret between this PHP server and the NestJS proxy.
> Set the same value as `AUTH_TOKEN` in `.envs/main/secret.yaml` of the NestJS stack.

## Environment variables

| Variable | Default | Description |
|---|---|---|
| `ENVIRONMENT` | *(unset)* | Set to `dev` to use docker service name for internal calls |
| `TRADEBOT_PHP_HOST` | `http://localhost` | Internal URL for cross-service PHP calls (user rights, etc.) |
| `SIGNALS_API_URL` | `http://localhost` | Used by `trade_ctrl_bot.php` when posting signals via the API |
| `BOT_SERVER_HOST` | `127.0.0.1` | Host of the bot/trading server for status queries |

## Apache virtual host example

```apache
<VirtualHost *:80>
    ServerName myserver.com
    DocumentRoot /var/www/signals-server

    <Directory /var/www/signals-server>
        Options -Indexes
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

The `.htaccess` file handles routing for the `api/users/*` endpoints.

## Telegram bot daemon

`trade_ctrl_bot.php` runs as a long-polling daemon. Use the shell wrapper to start it:

```bash
bash trade_ctrl_bot.sh
```

It will auto-restart on failure (5 second delay between restarts).

### Configuring user privileges

Edit `trade_ctrl_bot.php` and replace the placeholder usernames with the actual
Telegram usernames of your administrators:

```php
if ('admin_user' == $user) {
    $admin_id = $id;
    $allowed_privs[$id] = array(CMD_EDIT_COEF, CMD_EDIT_OFFSET, CMD_RESTART, ...);
}
```

> This is a temporary solution — the TODO comment in the code notes that privileges
> should be loaded from the database instead.

## Integration with NestJS (signals-server-ts)

The NestJS backend connects to this server via `SIGNALS_API_URL`. It calls:

- `GET  {SIGNALS_API_URL}/sig_edit.php?format=json&setup=N` — fetch signals
- `PATCH {SIGNALS_API_URL}/sig_edit.php?format=json&...` — edit a signal field  
- `PUT  {SIGNALS_API_URL}/sig_edit.php?format=json&...` — toggle a flag
- `POST  {SIGNALS_API_URL}/sig_edit.php?format=json` — add a signal
- `DELETE {SIGNALS_API_URL}/sig_edit.php?format=json&delete=N` — delete a signal

All requests from NestJS carry `Authorization: Bearer <FRONTEND_TOKEN>` and
`X-User-Id: <telegram_id>` headers, verified by `check_auth()` in `api_helper.php`.
