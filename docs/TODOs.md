# TODOs - Trading Platform PHP

> Актуализация на 2026-04-15 по фактическим `TODO/FIXME/#TODO` в коде.
> Источники: `src/*.php`, `signals-server/*.php`, `src/web-ui/*.php`, `utils/*.php`.
> `vendor/` и внешние зависимости исключены из списка.

---

## 1) Ясно что и как делать (детерминированные задачи)

### Конфигурирование и вынос хардкода
- `src/orders_lib.php:324` - epsilon вынести в конфиг
- `src/orders_lib.php:821` - порог для негативных/слишком малых цен вынести в конфиг
- `src/market_maker.php:904` - rounding брать из конфига
- `src/trade_config.php:20` - `working_source` загружать из конфига
- `src/bot_globals.php:11` - `ABNORMAL_HIGH_COST` вынести в конфиг
- `src/rest_api_common.php:534` - автопереключение mirror при проблемах
- `src/impl_bitfinex.php:1373` - debug TTL вынести в конфиг
- `signals-server/sig_import.php:44` - список allowed hidden в конфиг

### Прямые рефакторинги (без неопределенной бизнес-логики)
- `src/trading_loop.php:671` - извлечь single position trade в функцию
- `src/ticker_info.php:238` - общий глобальный кеш ticker info
- `src/ext_signals.php:1577` - перенос `min_clean` в constructor
- `src/exec_opt.php:108` - явно оформить tick_size rounding (сейчас TODO без описания)
- `src/trading_context.php:133` - устранить дублирование `pair_id` с `tinfo->pair_id`
- `src/web-ui/trading_stats.php:10` - загрузка total pairs map

### Технические улучшения в БД/данных
- `src/orders_lib.php:1312` - заменить паттерн на `UPDATE` для эффективности
- `signals-server/parsepos.php:104` - оптимизация создания таблицы
- `signals-server/load_sym.inc.php:69` - использовать таблицу фильтра bad symbols
- `signals-server/signal_data.php:729` - добавить story log для сигналов
- `src/web-ui/trades_report.php:658` - генерация таблиц summary для остальных бирж

### Явные недоделки/расширения API и UI
- `src/trading_core.php:183` - toggle notify-orders через Telegram
- `src/reporting.php:161` и `src/reporting.php:506` - monitor URL из конфигурации
- `src/reporting.php:290` - dump последних fail-событий
- `src/tele-bot/trade_ctrl_bot.php:160` - обработка части команд
- `src/tele-bot/trade_ctrl_bot.php:632` - таймер удаления файла
- `signals-server/trade_ctrl_bot.php:379` - таймер удаления файла
- `signals-server/trade_ctrl_bot.php:572` - обработка части команд
- `signals-server/trade_ctrl_bot.php:493` - загрузка прав из БД
- `src/web-ui/api/chart/index.php:27` - вернуть production-ветку без hardcoded date

---

## 2) Потенциальная эпопея / отладка / спорная логика

### Ордера, позиции, торговый контур
- `src/impl_binance.php:605` и `src/impl_binance.php:635` - cancel lost order (зависшие ордера)
- `src/impl_binance.php:645` - re-explain STP cancellation сценария
- `src/impl_binance.php:436` - перенос canceled ордеров в archive
- `src/impl_binance.php:841` - сверка pending vs open (kill/full match сценарии)
- `src/impl_binance.php:818` - actualize many symbols (масштаб и периодичность неочевидны)
- `src/trading_engine.php:1612` - использовать actual target_pos
- `src/trading_engine.php:1483` - восстановление удаленных batches при существующих ордерах
- `src/trading_engine.php:1172` - обработка отсутствия ticker info (сейчас return 0)
- `src/trading_loop.php:94` - обработка ошибки вместо silent continue
- `src/trading_loop.php:984` - reopen batch "not correct" (риск регрессий)
- `src/trading_loop.php:789` - временный zero_cross limiter (нужна проверка и удаление)

### Оптимизация/математика исполнения (высокий риск скрытых эффектов)
- `src/trading_engine.php:1214` - упростить перепаковку batches
- `src/trading_engine.php:1293` - убрать дублирование математики оптимизации
- `src/trading_engine.php:1298` - epsilon по minimal step/USD cost вместо coarse coef
- `src/trading_engine.php:1464` - ugly hack (slippage path)
- `src/market_maker.php:549` - полноценная обработка оптимизации сигнала
- `src/market_maker.php:580` и `src/market_maker.php:2266` - коррекция delta в negative
- `src/market_maker.php:2134` - добавить закрывающие заявки по обе стороны спреда
- `src/ext_signals.php:764` - epsilon в поиске ордеров
- `src/ext_signals.php:992` - использовать signal prices при наличии
- `src/ext_signals.php:406` - нужны тесты набора заявок (в т.ч. закрывающих)

### Биржевые интеграции и безопасность
- `src/impl_deribit.php:1518` - insecure auth method (требует аккуратной миграции)
- `src/impl_deribit.php:596` - инструменты для всех валют
- `src/impl_deribit.php:1084` - processing completed orders
- `src/impl_bitmex.php:1503` - проверка валюты пары (spot/futures)
- `src/impl_bitmex.php:1581` - детекция инструментов по публичному API response
- `src/impl_bitmex.php:619` - dirty hack quanto detection
- `src/impl_bitmex.php:1666` - configurable range displayQty
- `src/impl_binance.php:236` - более предпочтительная пара для BTC price

### Инфраструктура, отказоустойчивость, recovery
- `src/bot_manager.php:712` - выбор opposite DB server
- `src/bot_manager.php:723` - recovery attempts/strategy
- `src/bot_manager.php:1001` - redundancy config + вызов `RedudancyCheck()`
- `src/backup_tables.php:144` - полноценная error-check обработка
- `src/trade_config.php:126` - need implementation
- `src/bot_globals.php:4` - формализовать интерфейс логгера

### Сигнальный сервер и web edge-cases
- `signals-server/lastpos.php:43` - ugly hack с `src_account`
- `signals-server/lastpos.php:87` - bad format: один position на `pair_id`
- `signals-server/parsepos.php:94` - warn при отсутствии pair_map
- `signals-server/sig_edit.php:61` - auth token + cookies
- `signals-server/trade_event.php:197` - пересмотр default event level
- `src/web-ui/trades_report.php:478` - cross-zero checking/reversal timestamp
- `src/web-ui/aggr_trade.php:8` - пустой TODO (нужно сформулировать)
- `utils/market_tracker.php:289` - обработка ошибки вместо `return 0`

---

## 3) Похоже уже реализовано / устарело (из прошлого списка)

Эти пункты были в старой версии `docs/TODOs.md`, но соответствующий TODO-комментарий в текущем коде не найден:

- `src/orders_lib.php:886` - remove after tables upgrade
- `src/trading_loop.php:688` - SetupLimits (теперь остался без текста TODO на `src/trading_loop.php:708`)
- `src/impl_bitmex.php:1231` / `1309` - line references устарели (остались смежные TODO на других строках)
- `src/impl_deribit.php:122` - line reference устарел (`TODO for all currencies` теперь на `src/impl_deribit.php:596`)
- `src/market_maker.php:465/496/1904` - line references устарели (TODO переехали на `549/580/2134/2266`)
- `src/trading_engine.php:1166/1208/1287/1292/1469/1598` - line references устарели (TODO актуальны, но на новых строках)
- `src/trading_loop.php:93/144/154/240/315/344/651/769/951` - line references устарели (TODO актуальны, но на новых строках)
- `src/orders_lib.php:251/259/685/1136/1161` - line references устарели (TODO актуальны, но на новых строках)
- `src/reporting.php:150/279/495` - line references устарели (TODO актуальны на `161/290/506`)

Дополнительно:
- Найдены новые TODO, которых не было в предыдущем списке: `src/trading_math.php:31`, `src/orders_lib.php:873`, `src/orders_lib.php:1274`, `src/impl_deribit.php:1084`, `signals-server/sig_edit.php:61`, `signals-server/trade_event.php:197`, `utils/market_tracker.php:289`.

---

## 4) Отложенный стратегический блок

- [ ] Replace PNG rendering paths that currently depend on php-gd with SVG rendering in bot/report images after system stabilization.
  Context: bot in hive may fail image rendering when php-gd is unavailable; SVG should remove this runtime dependency.
  Trigger: execute after trading/runtime stabilization phase is complete.
