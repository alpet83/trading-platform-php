# TODOs - Trading Platform PHP

> Собрано из TODO/FIXME комментариев кодовой базы. Отсортировано по приоритетности.

---

## 🔴 HIGH PRIORITY - Проблемы и потенциальные баги

### Исполнение ордеров и ошибки
- `src/impl_binance.php:497` - TODO: cancel lost order - зависшие ордера не отменяются
- `src/impl_binance.php:521` - TODO: cancel lost order - зависшие ордера не отменяются
- `src/impl_binance.php:530` - TODO: need re-explain - статус ордера требует разъяснения (self-trading prevention)
- `src/impl_binance.php:349` - TODO: move to orders archive - перемещение canceled ордеров в архив

### Обработка позиций и батчей
- `src/trading_engine.php:1166` - TODO: error/warn - отсутствие TickerInfo не обрабатывается
- `src/trading_engine.php:1598` - TODO: use actual target_pos! - используется неактуальная позиция
- `src/trading_engine.php:1469` - TODO: restore deleted batches - восстановление удаленных батчей
- `src/trading_loop.php:93` - TODO: error need handle - пропуск тикеров без обработки ошибки

### Безопасность
- `src/impl_deribit.php:932` - TODO: little unsecured auth method - небезопасный метод авторизации
- `src/orders_lib.php:685` - TODO: use config for negative prices - проверка валидности цен

### Binance-specific
- `src/impl_binance.php:172` - TODO: use more preferable pair - определение BTC цены через неоптимальную пару
- `src/impl_binance.php:679` - TODO: actualize many symbols! - актуализация множества символов
- `src/impl_binance.php:701` - TODO: scan pending vs open - сверка открытых и ожидающих ордеров

---

## 🟡 MEDIUM PRIORITY - Качество кода и упрощение

### Рефакторинг математики и оптимизации
- `src/trading_engine.php:1287` - TODO: дублирование математики оптимизации... надо избавляться
- `src/trading_engine.php:1292` - TODO: detect by minimal trading step / USD cost - детекция минимального шага
- `src/trading_engine.php:1208` - TODO: перепаковку надо упростить - упрощение создания батчей
- `src/market_maker.php:465` - TODO: здесь должна развернуться обработка оптимизации сигнала
- `src/market_maker.php:496` - TODO: delta need also correction to negative
- `src/market_maker.php:1904` - TODO: delta need also correction to negative

### Грязные хаки и костыли
- `src/trading_engine.php:1450` - TODO: this is ugly hack - расчет slippage
- `src/trading_loop.php:240` - TODO: here is very ugly code, need change to elegant
- `src/orders_lib.php:251` - TODO: тут злостные костыли - сохранение ордеров
- `src/trading_loop.php:769` - TODO: remove after testing zero_cross limiter
- `src/trading_loop.php:951` - TODO: reopen, but is not correct
- `src/trading_loop.php:315` - #TODO: remove bitmex debug info
- `src/trading_loop.php:344` - TODO: use config value, add price breaking
- `src/market_maker.php:1798` - TODO: Текущая реализация не подразумевает добавление закрывающих заявок

### Оптимизация базы данных
- `src/orders_lib.php:1136` - TODO: need using UPDATE TABLE for efficiency
- `src/orders_lib.php:1161` - TODO: this cleanup can destroy old orders - риск удаления старых ордеров
- `src/orders_lib.php:886` - TODO: remove after tables upgrade - миграция таблиц

### Конфигурация
- `src/orders_lib.php:259` - TODO: epsilon from config - вынести эпсилон в конфиг
- `src/trading_loop.php:144` - TODO: need smart handling - обработка BTC пар
- `src/trading_loop.php:154` - TODO: debug bias unexpected volatility - debug-код для волатильности
- `src/market_maker.php:772` - TODO: use config roundings - использовать конфиг для rounding

### Обработка валют и пар
- `src/impl_bitmex.php:1231` - TODO: check currency of pair - проверка валюты пары (спот/фьючерсы)
- `src/impl_bitmex.php:1309` - TODO: detect by responce of public API - детекция инструментов
- `src/impl_deribit.php:122` - TODO: for all currencies - загрузка инструментов для всех валют

### Очистка данных
- `src/impl_bitfinex.php:283` - TODO: remove trash via regEx - очистка данных регуляркой

---

## 🟢 LOW PRIORITY - Улучшения и новые фичи

### Telegram уведомления
- `src/trading_core.php:163` - TODO: must be toggled by Telegram - включение уведомлений через Telegram

### Мониторинг и конфигурация
- `src/reporting.php:150` - TODO: monitor URL retrieve from configuration
- `src/reporting.php:279` - TODO: last 10 fails dump
- `src/reporting.php:495` - TODO: monitor URL retrieve from configuration
- `src/rest_api_common.php:409` - TODO: autoselect mirror if problems...

### Market Maker улучшения
- `src/exec_opt.php:108` - TODO: tick_size rounding
- `src/exec_opt.php:148` - TODO: apply max_exec_time
- `src/exec_opt.php:266` - TODO: check time

### Trading Loop рефакторинг
- `src/trading_loop.php:651` - TODO: extract single position trade to function
- `src/trading_loop.php:688` - TODO: SetupLimits

### Signals Server
- `src/ext_signals.php:323` - TODO: нужны тесты с разным набором заявок
- `src/ext_signals.php:615` - TODO: use epsilon
- `src/ext_signals.php:810` - TODO: need use signal prices, if available
- `src/ext_signals.php:1324` - TODO: move to constructor

---

## 🔵 INFRASTRUCTURE - Инфраструктура и конфигурация

### Конфигурация
- `src/bot_globals.php:4` - TODO: need assume interface
- `src/bot_globals.php:11` - TODO: move to config (ABNORMAL_HIGH_COST)
- `src/trade_config.php:21` - TODO: load from config!
- `src/trade_config.php:115` - TODO: need implementation
- `src/ticker_info.php:238` - TODO: make cache ticker info global method

### Хаrdcoded значения
- `src/lib/ip_config.php:2` - #TODO/WARN: hard coded IP адреса

### Репликация и восстановление
- `src/bot_manager.php:683` - TODO: select opposite DB server
- `src/bot_manager.php:694` - TODO: may not help, count recovery attemps
- `src/bot_manager.php:972` - TODO: use redudancy config, for call RedudancyCheck()

### Бекапы
- `src/backup_tables.php:144` - TODO: error check

---

## ⚪ WEB UI / API - Фронтенд и API

### Signals Server Web UI
- `signals-server/lastpos.php:43` - TODO: ugly hack here
- `signals-server/lastpos.php:87` - TODO: bad format, one position per pair_id
- `signals-server/load_sym.inc.php:69` - TODO: use bad symbols filter table
- `signals-server/parsepos.php:94` - TODO: need warn
- `signals-server/parsepos.php:104` - TODO: optimize table creation
- `signals-server/signal_data.php:729` - TODO: add signal story log

### Trading Stats
- `src/web-ui/trading_stats.php:10` - TODO: load pairs map total
- `src/web-ui/trading_stats.php:131` - TODO: scan 1M candles

### Trades Report
- `src/web-ui/trades_report.php:478` - TODO: cross zero checking
- `src/web-ui/trades_report.php:658` - TODO: генерация таблиц для других бирж

### Прочее
- `src/web-ui/aggr_trade.php:8` - TODO: (пустой комментарий)
- `src/web-ui/api/chart/index.php:27` - TODO: раскомментировать на проде
- `src/trading_context.php:133` - TODO: duplicate of tinfo->pair_id
- `signals-server/trade_ctrl_bot.php:493` - TODO: use DB for loading privs!
- `signals-server/sig_import.php:44` - TODO: hide in config

---

## ⚠️ LIKELY OBSOLETE - Вероятно устаревшие

### Debug-код (возможно уже не нужен)
- `src/impl_bitfinex.php:302` - TODO: remove after testing
- `src/impl_bitfinex.php:845` - TODO: use config var (TTL для debug)
- `src/impl_deribit.php:728` - TODO: range must be configurable (тестирование rand)
- `src/impl_bitmex.php:1394` - TODO: range must be configurable (тестирование rand)
- `src/impl_bitmex.php:352` - TODO: this is dirty hack (quanto detection)
- `src/orders_batch.php:409` - TODO: use valid prices
- `src/lib/trading_info.php:236` - TODO: save to file queue;

### Telegram боты (возможно неактуально)
- `src/tele-bot/trade_ctrl_bot.php:160` - TODO: handle some commands
- `src/tele-bot/trade_ctrl_bot.php:632` - TODO: post timer to delete file
- `signals-server/trade_ctrl_bot.php:379` - TODO: post timer to delete file
- `signals-server/trade_ctrl_bot.php:572` - TODO: handle some commands

---

## 🔧 REFACTORING PLAN - Масштабные рефакторинги

### 1. Переименование БД сигнального сервера: `trading` → `sigsys`

**Статус:** ✅ **PHASE 2 COMPLETED** (2026-04-13)

**Проблема.** Сигнальный сервер (sigsys-ts / signals-server) использует БД с именем `trading` —
совпадает с основной trading-БД платформы. Это источник путаницы: в docker-окружении хост и
имя БД сходятся в одном параметре, что уже приводило к передаче имени хоста вместо имени БД
(`init_remote_db('trd-web.trd_default')` вместо `init_remote_db('trading')`).

**Целевое имя:** `sigsys`

**Phase 1 (Completed):**
- ✅ Обновлены `docker-compose.signals-legacy.yml` переменные на `sigsys` (2 места)
- ✅ Добавлена секция `$db_configs['sigsys']` в `secrets/signals_db_config.php.example` с алиасом `trading`
- ✅ Расширен список кандидатов БД в `signals-server/api_helper.php` и `trade_ctrl_bot.php`
- ✅ Создан тройной fallback chain для username/password поиска (sigsys-первый)
- ✅ Windows cmd->ps1 вrappers добавлены для развёртывания

**Phase 2 (Completed - 2026-04-13):**
- ✅ Мигрировано 17 PHP файлов `signals-server/` с `init_remote_db('trading')` → `init_remote_db('sigsys')`
  - Затронуты файлы: get_user_rights.php, get_signals.php, pairs_map.php, api/users/*.php, parsepos.php и др.
- ✅ Обновлена документация в `api_usage.markdown` (4 примера кода)
- ✅ Синхронизированы изменения в обоих деревьях (runtime и clean test tree)
- ✅ Ошибка в tele_hook.php обновлена: `DB trad­ing` → `DB sigsys`

**Совместимость (Preserved):**
- Алиас `trading` остаётся в `secrets/signals_db_config.php.example` для миграционного периода
- Fallback chain в `api_helper.php` и `trade_ctrl_bot.php` гарантирует работу обоих имён
- Основная trading-БД платформы остаётся нетронутой (scope ограничён только legacy signals)

**Phase 3 (Pending - аліас removal):**
- После production validation: убрать `trading` из кандидатов использования в fallback chain
- Полная миграция данных legacy signals (если требуется переимущество старых данных)
- Обновление документации (docs/TODOs.md и guides)

---

### 2. Разделение исходников `src/` по подкаталогам

**Проблема.** Все ~40 PHP-файлов лежат в корне `src/` вперемешку: ядро системы, движки бирж,
вспомогательные утилиты, CLI-инструменты. Навигация затруднена, зависимости неочевидны.

**Предлагаемая структура:**

```
src/
├── core/               # Ядро: абстрактный движок и цикл
│   ├── trading_core.php
│   ├── trading_engine.php
│   ├── trading_loop.php
│   ├── trading_common.php
│   ├── trading_context.php
│   ├── trade_config.php
│   ├── trade_logging.php
│   ├── exec_opt.php
│   └── bot_globals.php
│
├── engines/            # Реализации конкретных бирж
│   ├── rest_api_common.php
│   ├── ws_api_common.php
│   ├── impl_binance.php
│   ├── impl_bitmex.php
│   ├── impl_bybit.php
│   ├── impl_bitfinex.php
│   └── impl_deribit.php
│
├── orders/             # Ордера и батчи
│   ├── orders_lib.php
│   ├── orders_batch.php
│   └── market_maker.php
│
├── feeds/              # Позиции, сигналы, тикеры
│   ├── pos_feed.php
│   ├── ext_signals.php
│   ├── pairs_map.php
│   ├── ticker_info.php
│   └── last_pos.php
│
├── reporting/          # Репортинг и логи
│   ├── reporting.php
│   └── log_cleanup.php
│
├── infra/              # Инфраструктура: БД, события, бэкапы
│   ├── db_client.php
│   ├── event_sender.php
│   ├── backup_tables.php
│   ├── db_backup.php
│   └── datafeed_manager.php
│
├── lib/                # (уже существует) утилиты и конфигурация
├── cli/                # (уже существует) CLI-инструменты
├── web-ui/             # (уже существует) веб-интерфейс
│
├── bot_manager.php     # Точка входа менеджера ботов
├── bot_instance.php    # Точка входа экземпляра бота
├── common.php          # Глобальный bootstrap
└── compos.php          # Composer autoload wrapper
```

**Scope изменений:**
- Переместить файлы по каталогам
- Обновить все `require`/`include` (их много в `common.php`, `bot_instance.php`, `bot_manager.php`)
- Обновить пути в `Dockerfile` и скриптах запуска (`run-bot.sh`)
- Обновить пути в `src/config/` если там есть абсолютные ссылки

**Порядок выполнения:**
1. Аудит всех `require`/`require_once`/`include` — построить граф зависимостей
2. Начать с `engines/` — наименее связанный с bootstrap-файлами слой
3. Затем `orders/`, `feeds/`, `reporting/`
4. Последним — `core/` (максимальное число входящих зависимостей)
5. `common.php` обновить последним как центральный регистр include'ов

---

## 📋 Deferred из предыдущего списка

- [ ] Replace PNG rendering paths that currently depend on php-gd with SVG rendering in bot/report images after system stabilization.
  Context: bot in hive may fail image rendering when php-gd is unavailable; SVG should remove this runtime dependency.
  Trigger: execute after trading/runtime stabilization phase is complete.

---

## Статистика по файлам

| Файл | Кол-во |
|------|--------|
| src/trading_engine.php | 7 |
| src/trading_loop.php | 10 |
| src/orders_lib.php | 6 |
| src/market_maker.php | 5 |
| src/impl_binance.php | 6 |
| signals-server/lastpos.php | 2 |
| signals-server/trade_ctrl_bot.php | 3 |
| src/bot_manager.php | 3 |
| src/ext_signals.php | 4 |
| src/impl_deribit.php | 3 |
| src/impl_bitmex.php | 3 |