# Trading Platform PHP Wiki (RU)

Это точка входа в документацию для практического запуска.
Если вы трейдер и хотите быстро поднять систему на одном хосте, начните с easy-way сценария ниже.

Коротко: создайте `C:\Trading`, распакуйте туда репозитории из ZIP и запускайте шаги по порядку.

## Начать отсюда

1. [Demo Deploy (Single Host) Step by Step (RU)](./Demo-Deploy-Single-Host.ru.md)
2. [Home (EN)](./Home.md)
3. [Demo Deploy (Single Host) Step by Step (EN)](./Demo-Deploy-Single-Host.md)

## Карта документации

1. Обзор платформы: [README.md](../../README.md)
2. Базовая инфраструктура: [INFRASTRUCTURE_GUIDE.md](../INFRASTRUCTURE_GUIDE.md)
3. Справочник команд: [COMMANDS_REFERENCE.md](../COMMANDS_REFERENCE.md)
4. Быстрый путь для Windows: [QUICKSTART_WINDOWS.md](../QUICKSTART_WINDOWS.md)
5. Контейнер legacy signals API: [SIGNALS_LEGACY_CONTAINER.md](../SIGNALS_LEGACY_CONTAINER.md)
6. Тома и секреты: [DOCKER_VOLUMES_AND_SECRETS.md](../DOCKER_VOLUMES_AND_SECRETS.md)
7. Усиление безопасности: [PRODUCTION_SECURITY.md](../PRODUCTION_SECURITY.md)

## Что решает эта wiki

- Даёт один основной сценарий деплоя на одном хосте.
- Убирает хаос в навигации за счёт ссылок в порядке выполнения.
- Объясняет роли сборок простым языком: мастер сигналов, улей ботов, Telegram UI.

## Рекомендуемый порядок чтения

1. Пройти [Demo Deploy (Single Host) Step by Step (RU)](./Demo-Deploy-Single-Host.ru.md).
2. Открывать [COMMANDS_REFERENCE.md](../COMMANDS_REFERENCE.md), когда нужен copy-paste вариант команд.
3. Открывать [SIGNALS_LEGACY_CONTAINER.md](../SIGNALS_LEGACY_CONTAINER.md), если нужен legacy bridge endpoint.
4. Обязательно пройти [PRODUCTION_SECURITY.md](../PRODUCTION_SECURITY.md) перед внешним прод-развертыванием.
