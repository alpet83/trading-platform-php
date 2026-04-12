# DEBUG_SMOKE_TESTS — Инструкция по runtime-отладке ботов

## Механизм debug.inc.php

Каждый торговый цикл `TradingCore::Update()` выполняет:

```php
$fname = shell_exec('ls *debug.inc.php | head -n 1');
if ($this->active && str_in($fname, 'debug.inc.php') &&
        file_exists($fname) && $uptime > 240 && $minute < 58) {
    include($fname);
}
```

**Условия выполнения:**
- файл `*debug.inc.php` существует в рабочей директории бота (`/app/src/`)
- `$this->active == true`
- uptime > 240 с (бот проработал > 4 минут)
- текущая минута < 58

**Рабочая директория** — `/app/src/` (на хосте: `P:/opt/docker/trading-platform-php/src/`).

Все активные боты (bitmex, binance, bitfinex) пытаются включить файл при каждом цикле.
Чтобы ограничить выполнение одним ботом — используйте проверку в начале файла.

---

## Контекст (доступные переменные)

| Переменная | Тип | Описание |
|------------|-----|----------|
| `$this` | `TradingCore` | основной объект бота |
| `$engine` | `TradingEngine` | движок биржи (BitMexEngine и т.д.) |
| `$uptime` | `int` | секунды с момента старта |
| `$minute` | `int` | текущая минута (0–59) |
| `$master` | `bool` | является ли инстанс мастером |

**Полезные методы `$this` (TradingCore):**

```php
$this->LogMsg("~C92 format %s~C00", $val);     // → core.log (зелёный)
$this->LogError("~C91 error~C00");              // → errors.log + core.log
$this->LogOrder("text");                        // → order.log
$this->LogObj($obj, '  ', 'label');             // дамп объекта в core.log
$this->Engine()                                 // → TradingEngine
$this->CurrentPos($pair_id)                     // → ComplexPosition|null
$this->signal_feed->CalcPosition($pair_id)      // target позиция по сигналам
$this->signal_feed->CollectSignals('all', $pair_id, true) // активные сигналы
$this->pairs_map                                // [pair_id => symbol]
```

**Полезные методы `$engine` (BitMexEngine):**

```php
$engine->account_id                             // int
$engine->exchange                               // 'BitMEX'
$engine->RequestPrivateAPI($path, $params, $method) // REST вызов
$engine->RequestPublicAPI($path, $params)       // публичный REST
$engine->TickerInfo($pair_id)                   // TickerInfo объект
```

**Цвета в LogMsg:**
- `~C91` = красный, `~C92` = зелёный, `~C93` = жёлтый, `~C94` = синий
- `~C95` = фиолетовый, `~C96` = cyan, `~C97` = белый, `~C00` = сброс

---

## Готовый файл тестов

`src/debug.inc.php` — универсальный файл с набором тестов для bitmex_bot.

Переключение теста — через `$core->dbg_test`:

```php
// Установить прямо в debug.inc.php (строка 26):
$DBG_TEST = $core->dbg_test ?? 'smoke';

// Или задать из другого места (persistent через цикл):
$core->dbg_test = 'orders';
```

### Доступные тесты

| ID | Что проверяет |
|----|---------------|
| `smoke` | Доступность API, права ключа |
| `orders` | Открытые ордера REST vs локальный движок |
| `margin` | Баланс счёта, доступная маржа, unrealised PnL |
| `tickers` | Котировки в памяти + листинг на бирже |
| `position` | Открытые позиции REST vs локальный кэш |
| `signals` | Активные сигналы, target/current позиция |

---

## Быстрый старт

### 1. Включить debug-режим

Файл уже есть в `src/debug.inc.php`. Чтобы он начал выполняться достаточно убедиться, что бот запущен и uptime > 4 минут. Перезапускать бота **не нужно** — файл подхватывается автоматически.

Проверить что файл выполняется:
```bash
tail -f P:/opt/docker/trading-platform-php/var/log/bitmex_bot/*/$(date +%Y-%m-%d)/core.log \
  | grep -a "\[DBG\]"
```

### 2. Переключить тест

Отредактировать строку в `src/debug.inc.php`:
```php
$DBG_TEST = $core->dbg_test ?? 'margin';  // ← изменить тест здесь
```

Файл перечитывается при **следующем торговом цикле** (обычно каждые 60 с.) без перезапуска бота.

### 3. Ограничить тест одним аккаунтом

```php
if ($engine->account_id != 864208)
    return;  // только первый bitmex аккаунт
```

### 4. Написать одноразовый тест

Создать отдельный файл и переименовать при деплое:
```bash
cp src/debug.inc.php src/mytest.debug.inc.php
# shell_exec('ls *debug.inc.php') найдёт оба файла по маске
```

Или использовать метку `goto SKIP_WORK` для выхода:
```php
<?php
$core = $this;
$engine = $core->Engine();
if (!str_in($engine->exchange, 'bitmex')) return;

// ... ваш тест ...

SKIP_WORK:
```

### 5. Убрать debug-файл

```bash
rm P:/opt/docker/trading-platform-php/src/debug.inc.php
# На следующем цикле бот просто не найдёт файл и продолжит работу
```

---

## Примеры конкретных проверок

### Проверить что ордер попал на биржу

```php
$json = $engine->RequestPrivateAPI('api/v1/order',
    ['filter' => json_encode(['clOrdID' => 'Account_864208-IID_12345'])], 'GET');
$this->LogMsg("[DBG/check_order] %s", $json);
```

### Посмотреть состояние позиции vs target

```php
foreach ($this->pairs_map as $pid => $sym) {
    $cp     = $this->CurrentPos($pid);
    $target = $this->signal_feed->CalcPosition($pid);
    $this->LogMsg("[DBG] %-10s current=%.6f target=%.6f delta=%.6f",
        $sym, $cp ? $cp->amount : 0, $target,
        ($cp ? $cp->amount : 0) - $target);
}
```

### Проверить sideEffectType (binance)

```php
// Только для binance_bot:
if (!str_in($engine->exchange, 'binance')) return;
// Сделать тестовый запрос к sapi/v3/margin/order...
```

### Одноразовое действие (не повторять)

```php
if (isset($core->my_task_done)) return;
$core->my_task_done = true;

// выполнится только один раз за сессию
$this->LogMsg("[DBG] one-shot task executed");
```

---

## Паттерны защиты от зацикливания

```php
// 1. Один раз в минуту (уже есть в src/debug.inc.php)
$_dbg_key = 'dbg_run_min_' . $minute;
if (!empty($core->$_dbg_key)) return;
$core->$_dbg_key = true;

// 2. Один раз за сессию
if (isset($core->dbg_done)) return;
$core->dbg_done = true;

// 3. Раз в N минут
if (($minute % 5) != 0) return;

// 4. Ограничение по времени выполнения
set_time_limit(30);
```

---

## Структура логов

Вывод debug-кода попадает в `core.log` (через `LogMsg`) или `errors.log` (через `LogError`):

```
var/log/bitmex_bot/{account_id}/{YYYY-MM-DD}/core.log
var/log/bitmex_bot/{account_id}/{YYYY-MM-DD}/errors.log
```

Удобный live-просмотр с фильтрацией:
```bash
ACCOUNT=864208
TODAY=$(date +%Y-%m-%d)
tail -f P:/opt/docker/trading-platform-php/var/log/bitmex_bot/$ACCOUNT/$TODAY/core.log \
  | sed 's/\x1b\[[0-9;]*m//g' | grep --line-buffered "\[DBG\]"
```

---

## Точечный перезапуск бота через debug.inc.php

`Shutdown()` доступен прямо из `debug.inc.php` — `$this` и есть `TradingCore`.
После вызова `active = false`, `bots-hive` перезапустит процесс автоматически.

Фильтрация по `account_id` и `start_ts` гарантирует, что перезапустится именно
нужный аккаунт и именно та сессия, которая видна в логах.

### Из каких логов брать start_ts

`$this->start_ts` — миллисекунды с эпохи, устанавливается на старте. Первая строка
любого `core.log` содержит метку времени старта:

```
[26-04-07 18:00:00.273]  PID file ../bitfinex_bot.pid
```

Значение можно распечатать прямо из debug.inc.php:

```php
$this->LogMsg('[DBG] start_ts = %d  account_id = %d', $this->start_ts, $engine->account_id);
```

### Шаблон: перезапустить конкретный аккаунт конкретной сессии

```php
<?php
$core   = $this;
$engine = $core->Engine();

// ── фильтр ──────────────────────────────────────────────────────────────
$ACCOUNT_ID  = 4220700;       // account_id из лога
$STARTED_AT  = 1744048800273; // start_ts (ms) из первой строки core.log
$TS_TOLERANCE = 5000;         // ±5 с — допуск на неточность чтения лога
// ────────────────────────────────────────────────────────────────────────

if ($engine->account_id !== $ACCOUNT_ID)  return;
if (abs($core->start_ts - $STARTED_AT) > $TS_TOLERANCE) return;

$core->LogMsg('~C93[DBG/restart]~C00 triggering Shutdown for account %d', $engine->account_id);
$core->Shutdown('debug-restart');
```

> **После выполнения** удалите или переименуйте `debug.inc.php` —
> иначе при следующем старте бот немедленно перезапустится снова.
