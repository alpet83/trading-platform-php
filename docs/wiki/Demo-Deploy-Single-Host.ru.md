# Demo Deploy (Single Host) Step by Step (RU)

Это простой сценарий "запусти и работай" для трейдера.
Подходит, если вы умеете скачивать проект, запускать скрипты и хотите быстро поднять рабочее демо на одном хосте.

## Базовая модель в 3 сборках

Думайте о системе как о трех сборках:

1. Сборка №1: центр сигналов и мастер-копирование.
2. Сборка №2: улей рабочих ботов на биржах.
3. Сборка №3: расширенный интерфейс управления сигналами с Telegram-авторизацией.

Для минимального demo на одном хосте хватает первых двух сборок, запущенных бок о бок.

## Что вы получите в easy-way

1. Рабочий endpoint сигналов (legacy bridge).
2. Рабочую торговую группу (`web` + `mariadb`) с базовым UI/API.
3. Понятную последовательность: сначала сигналы, потом торговля.

## 0) Что нужно заранее

1. Установлен Docker Desktop (или Docker Engine + Compose v2).
2. Создайте папку `C:\Trading`.
3. Скачайте ZIP-архивы нужных репозиториев и распакуйте их в `C:\Trading`.

Минимальный набор папок:

1. `C:\Trading\trading-platform-php`
2. `C:\Trading\alpet-libs-php`

Если вы работаете через GitHub, зависимости можно подтягивать автоматически, но для easy-way это не обязательно.

Для Windows держите рядом [QUICKSTART_WINDOWS.md](../QUICKSTART_WINDOWS.md).

## 1) Подготовка окружения (1-2 минуты)

1. Убедитесь, что есть `.env` (при необходимости скопируйте из `.env.example`).
2. Проверьте `WEB_PORT`, `WEB_PUBLISH_IP` и DB-переменные.
3. Держите секреты локально, не коммитьте реальные токены.

Базовая безопасность: [PRODUCTION_SECURITY.md](../PRODUCTION_SECURITY.md).

## 2) Шаг A: запуск сборки №1 (мастер сигналов)

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
2. Поднимает контейнеры центра сигналов.
3. Выполняет быстрый HTTP probe legacy endpoint.

Детали: [SIGNALS_LEGACY_CONTAINER.md](../SIGNALS_LEGACY_CONTAINER.md).

## 3) Шаг B: запуск сборки №2 (улей торговых ботов)

Linux/Git Bash:

```bash
sh scripts/deploy-simple.sh
```

PowerShell:

```powershell
./scripts/deploy-simple.ps1
```

Что делает этот этап:

1. Подготавливает окружение для торговой группы.
2. Поднимает `mariadb` и `web`.
3. Проверяет, что базовые страницы и API отвечают.

Детали команд: [COMMANDS_REFERENCE.md](../COMMANDS_REFERENCE.md).

## 4) Проверка, что всё действительно работает

Выполнить:

```bash
docker compose ps
```

Проверить:

1. Страница управления открывается: `http://127.0.0.1:8088/basic-admin.php`.
2. API отвечает: `http://127.0.0.1:8088/api/index.php`.
3. Legacy signals отвечает на `${SIGNALS_LEGACY_PORT:-8090}`.

Если проверка не проходит, посмотреть логи:

```bash
docker compose logs --tail 100 mariadb web
```

## 5) Проверка связки между сборками (самое важное)

1. Legacy endpoint отвечает на `${SIGNALS_LEGACY_PORT:-8090}`.
2. Trading admin/API отвечают на `${WEB_PORT:-8088}`.
3. При маршрутизации сигналов убедитесь, что `SIGNALS_API_URL` указывает на legacy bridge URL.

Если пункты 1-3 выполняются, easy-way сценарий готов.

## 6) Что дальше можно добавить

### 6.1 Datafeed (опционально)

Если сценарий требует datafeed loaders, убедитесь в наличии исходников datafeed и запустите стек с текущими compose-настройками.

Справка: [DATAFEED_DEPS.md](../DATAFEED_DEPS.md).

### 6.2 Ключи биржи для ботов (опционально)

Если планируется реальная торговля, загрузите ключи биржи через скрипты проекта.

Точка входа: [COMMANDS_REFERENCE.md](../COMMANDS_REFERENCE.md) и [BOT_MANAGER_AND_PASS.md](../BOT_MANAGER_AND_PASS.md).

### 6.3 Сборка №3: Telegram-интерфейс управления сигналами

Когда базовый easy-way уже работает, можно подключать `signals-server.ts` как отдельный слой управления.

Стартовые документы:

1. [signals-server.ts/README.md](../../signals-server.ts/README.md)
2. [signals-server/README.md](../../signals-server/README.md)

## 7) Финальный чеклист easy-way

1. `docker compose ps` показывает рабочие контейнеры.
2. Базовые admin/API endpoint отвечают.
3. Legacy API endpoint отвечает.
4. Секреты остаются локальными и не попадают в git status.

## 8) Куда идти дальше

1. Усиление безопасности: [PRODUCTION_SECURITY.md](../PRODUCTION_SECURITY.md)
2. Модель томов/секретов: [DOCKER_VOLUMES_AND_SECRETS.md](../DOCKER_VOLUMES_AND_SECRETS.md)
3. Переход к HA: [PAIR_REDUNDANCY_AUTOMATION.md](../PAIR_REDUNDANCY_AUTOMATION.md)
