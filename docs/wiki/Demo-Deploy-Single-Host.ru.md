# Demo Deploy (Single Host) Step by Step (RU)

Это головная пошаговая инструкция для демо-развертывания всех основных контейнеров на одном хосте.
Страница предназначена для быстрого онбординга и отсылает к детальным документам только по мере необходимости.

## Базовая модель (перед запуском)

На одном хосте обычно используются две контейнерные сборки:

1. Legacy signals сборка (`docker-compose.signals-legacy.yml`)
	Роль: поднять legacy signals API bridge (`signals-legacy`) и его БД.
2. Trading сборка (`docker-compose.yml`)
	Роль: поднять основной торговый стек (`mariadb`, `web`, опционально bot/datafeed).

Для минимального demo достаточно этих двух сборок:

1. Сначала поднимаем legacy signals (источник зависимостей по сигналам).
2. Потом поднимаем trading group (`deploy-simple.sh` или `deploy-simple.ps1`).

## Область сценария

Покрывает:

- `mariadb`
- `web`
- опционально `signals-legacy`
- опционально `datafeed`

Не покрывает: production HA на двух хостах. Для HA см. [PAIR_REDUNDANCY_AUTOMATION.md](../PAIR_REDUNDANCY_AUTOMATION.md).

## 0) Предусловия

1. Установлен Docker Desktop (или Docker Engine + Compose v2).
2. Репозиторий склонирован локально.
3. Доступен `P:/GitHub/alpet-libs-php` для bootstrap runtime includes.

Для Windows держите рядом [QUICKSTART_WINDOWS.md](../QUICKSTART_WINDOWS.md).

## 1) Подготовка окружения

1. Убедитесь, что есть `.env` (при необходимости скопируйте из `.env.example`).
2. Проверьте `WEB_PORT`, `WEB_PUBLISH_IP` и DB-переменные.
3. Держите секреты локально, не коммитьте реальные токены.

Базовая безопасность: [PRODUCTION_SECURITY.md](../PRODUCTION_SECURITY.md).

## 2) Stage A: деплой Legacy Signals (зависимость)

Linux/Git Bash:

```bash
sh scripts/deploy-signals-legacy.sh
```

PowerShell:

```powershell
./scripts/deploy-signals-legacy.ps1
```

Что делает этап:

1. Проверяет `secrets/signals_db_config.php` (если нет, копирует из примера).
2. Поднимает `signals-legacy-db` и `signals-legacy` через overlay compose.
3. Выполняет быстрый HTTP probe legacy endpoint.

Детали: [SIGNALS_LEGACY_CONTAINER.md](../SIGNALS_LEGACY_CONTAINER.md).

## 3) Stage B: деплой Trading Group

Linux/Git Bash:

```bash
sh scripts/deploy-simple.sh
```

PowerShell:

```powershell
./scripts/deploy-simple.ps1
```

Что делает этот этап:

1. Bootstrap runtime-файлов и генерация конфигов.
2. Сборка образов `mariadb` и `web`.
3. Поднятие БД и ожидание health.
4. Поднятие `web` и проверка базовых endpoint.

Детали команд: [COMMANDS_REFERENCE.md](../COMMANDS_REFERENCE.md).

## 4) Проверка базовых сервисов

Выполнить:

```bash
docker compose ps
```

Проверить:

1. Админ-страница доступна по `http://127.0.0.1:8088/basic-admin.php` (или вашему адресу публикации).
2. API entrypoint доступен по `http://127.0.0.1:8088/api/index.php`.

Если проверка не проходит, посмотреть логи:

```bash
docker compose logs --tail 100 mariadb web
```

## 5) Проверка связки между сборками

1. Legacy endpoint отвечает на `${SIGNALS_LEGACY_PORT:-8090}`.
2. Trading admin/API отвечают на `${WEB_PORT:-8088}`.
3. При маршрутизации сигналов убедитесь, что `SIGNALS_API_URL` указывает на legacy bridge URL.

## 6) Опционально: включить контейнер datafeed

Если сценарий требует datafeed loaders, убедитесь в наличии исходников datafeed и запустите стек с текущими compose-настройками.

Справка: [DATAFEED_DEPS.md](../DATAFEED_DEPS.md).

## 7) Опционально: загрузка bot credentials

Если планируется реальная торговля, загрузите ключи биржи через скрипты проекта.

Точка входа: [COMMANDS_REFERENCE.md](../COMMANDS_REFERENCE.md) и [BOT_MANAGER_AND_PASS.md](../BOT_MANAGER_AND_PASS.md).

## 8) Post-deploy чеклист

1. `docker compose ps` показывает рабочие контейнеры.
2. Базовые admin/API endpoint отвечают.
3. Legacy API endpoint отвечает.
4. Секреты остаются локальными и не попадают в git status.

## 9) Куда идти дальше

1. Усиление безопасности: [PRODUCTION_SECURITY.md](../PRODUCTION_SECURITY.md)
2. Модель томов/секретов: [DOCKER_VOLUMES_AND_SECRETS.md](../DOCKER_VOLUMES_AND_SECRETS.md)
3. Переход к HA: [PAIR_REDUNDANCY_AUTOMATION.md](../PAIR_REDUNDANCY_AUTOMATION.md)
