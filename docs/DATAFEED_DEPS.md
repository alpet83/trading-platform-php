# DATAFEED_DEPS

## Цель

Документ фиксирует зависимости и архитектуру интеграции `datafeed` в `trading-platform-php`:

1. отдельный контейнер `php:8.4-cli` для загрузчиков рынка;
2. сборка образа с внешними репозиториями и PHP-зависимостями;
3. единый источник `db_config.php` для всех сервисов;
4. резидентный mini-manager, который запускает почасовые загрузчики по биржам;
5. таблицы управления активными загрузчиками в БД `datafeed` для дальнейшего управления из админки.

## Workflow репозитория datafeed

Источник данных: `P:/opt/docker/datafeed` (синхронизирован с `cqds/projects/`).

Для прозрачной истории изменений источником правок считается локальный репозиторий `P:/opt/docker/datafeed`. Порядок работы:

1. правки в `P:/opt/docker/datafeed`;
2. commit/push в публичный `origin`;
3. уже затем pull/rebuild в runtime-среде.

## Что добавлено

1. Docker Compose service: `datafeed` в [docker-compose.yml](../docker-compose.yml).
2. Docker image build updates в [docker/php.Dockerfile](../docker/php.Dockerfile):
   1. clone `alpet-libs-php`;
   2. clone `datafeed`;
   3. install PHP packages `arthurkushman/php-wss`, `smi2/phpclickhouse`.
3. Entry-point: [docker/entrypoints/run-datafeed.sh](../docker/entrypoints/run-datafeed.sh).
4. Резидентный менеджер: [src/datafeed_manager.php](../src/datafeed_manager.php).
5. Bootstrap SQL: [docker/mariadb-init/40-bootstrap-datafeed-loader-manager.sql](../docker/mariadb-init/40-bootstrap-datafeed-loader-manager.sql).

## Единый db_config.php

Один файл `./secrets/db_config.php` используется одновременно в двух местах:

1. `/app/src/lib/db_config.php` для core-скриптов торговой платформы;
2. `/app/datafeed/lib/db_config.php` для загрузчиков `datafeed`.

Это сохраняет единый источник учетных данных и исключает рассинхрон конфигов.

## БД datafeed и минимальные обращения

1. `datafeed` DB создается bootstrap-скриптом при старте MariaDB.
2. Вне `cm_update.php` критично только хранение рыночных данных загрузчиков и таблиц их расписания/метаданных.
3. `cm_update.php` (CoinMarketCap) по умолчанию отключен (`enabled = 0`), поэтому внешняя зависимость CMC API не обязательна для базового runtime.

## Почему нужен mini-manager

Скрипты `*_candles_dl.php` и `*_ticks_dl.php` выполняются ограниченное время (рабочее окно около часа) и сами по себе не являются бессрочными демонами.

Из-за этого требуется резидентный процесс-оркестратор, аналогичный `bot_manager`, который:

1. живет постоянно в контейнере;
2. отслеживает, какие загрузчики включены;
3. запускает их индивидуально по расписанию;
4. фиксирует запуск/завершение/ошибки в БД.

## Таблицы mini-manager в datafeed

По умолчанию manager использует БД `datafeed`. Для legacy-инсталляций без грантов на `datafeed` реализован fallback в `trading` (только для запуска без простоя), но целевая конфигурация остается `datafeed`.

### datafeed.loader_control

Назначение: источник правды по включенным загрузчикам.

Ключевые поля:

1. `loader_key` — уникальный идентификатор;
2. `exchange` — биржа;
3. `script_name` — имя скрипта загрузчика;
4. `enabled` — флаг запуска;
5. `period_seconds` — частота запуска;
6. `timeout_seconds` — таймаут процесса;
7. `last_started_at`, `last_finished_at`, `last_exit_code`, `last_error` — диагностическая информация.

### datafeed.loader_activity

Назначение: heartbeat резидентного manager-процесса.

Ключевые поля:

1. `name`, `host`, `pid` — кто работает;
2. `state` — состояние;
3. `active_count` — число активных подпроцессов;
4. `ts_alive` — отметка свежести.

## Стартовый набор загрузчиков

Предзаполняются записи:

1. `binance_candles` -> `bnc_candles_dl.php`
2. `binance_ticks` -> `bnc_ticks_dl.php`
3. `bitmex_candles` -> `bmx_candles_dl.php`
4. `bitmex_ticks` -> `bmx_ticks_dl.php`
5. `bitfinex_candles` -> `bfx_candles_dl.php`
6. `bitfinex_ticks` -> `bfx_ticks_dl.php`
7. `bybit_candles` -> `bbt_candles_dl.php`
8. `coinmarketcap_update` -> `cm_update.php` (disabled)

## Логика работы manager

1. При старте проверяет/создает таблицы в `datafeed`.
2. На каждой итерации цикла читает `loader_control`.
3. Если loader включен и наступил срок запуска, стартует подпроцесс `php /app/datafeed/src/<script>`.
4. Контролирует завершение и таймаут, обновляет статус полей в `loader_control`.
5. Пишет heartbeat в `loader_activity`.

## Управление из админки

Сторона БД уже готова: включение/выключение идет через `datafeed.loader_control.enabled`.

Интеграция в UI админки может быть сделана отдельным шагом (чтение/обновление `loader_control`). На данном этапе вся логика управления уже централизована в таблице и готова к подключению формы.

## Операционные замечания

1. Реальный график по умолчанию — `3600` секунд для биржевых загрузчиков.
2. Для тяжелых исторических прогонов рекомендуется:
   1. ограничивать глубину истории (`DATAFEED_HISTORY_LIMIT_DAYS*`);
   2. включать только необходимые `loader_control.enabled`.
3. Если CoinMarketCap не нужен, `coinmarketcap_update` должен оставаться disabled.

## Root доступ в MariaDB

1. Контейнер сохраняет root пароль в общий секрет: `./secrets/mariadb_root_password`.
2. При пустом `MARIADB_ROOT_PASSWORD` пароль генерируется автоматически и сразу сохраняется в этот файл.
3. Для удобства root CLI в контейнере создается `/root/.my.cnf`.
4. Если root учетная запись в старом томе MariaDB уже повреждена/рассинхронизирована, потребуется отдельная процедура reset root; это не переигрывается автоматически.

## Быстрая проверка после деплоя

1. Убедиться, что контейнер `datafeed` запущен.
2. Проверить heartbeat в `datafeed.loader_activity`.
3. Проверить обновление `last_started_at` и `last_finished_at` в `datafeed.loader_control`.
4. Проверить лог `var/log/datafeed.log` и логи `var/log/datafeed-*.log`.

## Legacy тома MariaDB

Если контейнер MariaDB уже был инициализирован до этих изменений, init SQL не переигрывается автоматически. Тогда нужен разовый SQL от администратора БД:

1. `CREATE DATABASE IF NOT EXISTS datafeed;`
2. `CREATE DATABASE IF NOT EXISTS binance;`
3. `CREATE DATABASE IF NOT EXISTS bitmex;`
4. `CREATE DATABASE IF NOT EXISTS bitfinex;`
5. `CREATE DATABASE IF NOT EXISTS bybit;`
6. `GRANT ALL PRIVILEGES ON datafeed.* TO 'trading'@'%';`
7. `GRANT ALL PRIVILEGES ON binance.* TO 'trading'@'%';`
8. `GRANT ALL PRIVILEGES ON bitmex.* TO 'trading'@'%';`
9. `GRANT ALL PRIVILEGES ON bitfinex.* TO 'trading'@'%';`
10. `GRANT ALL PRIVILEGES ON bybit.* TO 'trading'@'%';`
11. `FLUSH PRIVILEGES;`
