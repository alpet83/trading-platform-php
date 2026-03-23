# Clean Deployment Admin Rights Logic

## Overview

При чистом деплое (когда в базе данных еще нет ни одной учетной записи), первый пользователь автоматически получает **админские права** вместо обычных `view` прав.

## Implementation Details

### 1. Added `countAll()` method to `TradingUsersRepository`

**File:** `src/modules/user/trading/trading-users.repository.ts`

```typescript
async countAll(): Promise<number> {
  return this.withConnection(async (connection) => {
    const [rows] = await connection.execute<[{ count: number }]>(
      'SELECT COUNT(*) as count FROM chat_users',
    );

    return rows.length > 0 ? rows[0].count : 0;
  });
}
```

- Возвращает общее количество пользователей в таблице `chat_users`
- Используется для определения, является ли создаваемый пользователь первым в системе

### 2. Injected `TradingUsersRepository` into `UserService`

**File:** `src/modules/user/user.service.ts`

- Добавлен импорт: `import { TradingUsersRepository }`
- Добавлена депенденсия в конструктор
- Обновлен модуль: `src/modules/user/user.module.ts` — добавлен импорт `TradingUsersModule`

### 3. Updated `createUser()` logic with first-user check

**File:** `src/modules/user/user.service.ts`

```typescript
async createUser(data: {...}): Promise<User> {
  // Проверяем, является ли это первым пользователем в системе
  let isFirstUser = false;
  try {
    const totalUsers = await this.tradingUsersRepository.countAll();
    isFirstUser = totalUsers === 0;
    if (isFirstUser) {
      this.logger.log('Первый пользователь в системе - выдаем админские права');
    }
  } catch (error) {
    this.logger.warn(`Ошибка при проверке количества пользователей: ${error.message}`);
  }

  // Определяем права - админские для первого пользователя, иначе только просмотр
  const rights = isFirstUser ? ['admin'] : ['view'];

  // ... rest of the logic uses 'rights' variable
  
  await this.externalService.createUser({
    enabled: 1,
    rights,  // будет ['admin'] или ['view']
    user_name: data.username,
    id: data.telegramId,
  }, ...);
}
```

**Особенности реализации:**

- Если проверка количества пользователей завершится с ошибкой (например, БД недоступна), пользователь все равно будет создан с правами `view` (graceful degradation)
- Логирует информацию о том, что создается первый пользователь
- Передает флаг `isFirstUser` в `adminNotifyService` для логирования события

### 4. Added unit tests

**File:** `src/modules/user/trading/trading-users.repository.spec.ts`

Добавлены два новых теста:
- ✅ `counts total users in the system` — проверяет, что `countAll()` возвращает правильное количество
- ✅ `returns 0 when countAll finds no users` — проверяет граничный случай (пустая таблица)

Всего тестов теперь: **17 passed** (было 15)

## Testing Results

```
PASS src/modules/user/trading/trading-users.repository.spec.ts (7 tests)
  ✓ maps rows from findAll into API-friendly users
  ✓ creates a user and returns selected row
  ✓ updates rights and enabled state
  ✓ returns delete status from affectedRows
  ✓ rejects invalid enabled flag before touching the DB
  ✓ counts total users in the system (NEW)
  ✓ returns 0 when countAll finds no users (NEW)

PASS src/modules/events/trading-events.repository.spec.ts (3 tests)
PASS src/common/logging/audit-log.service.spec.ts (2 tests)
PASS src/common/middleware/request-id.middleware.spec.ts (2 tests)
PASS src/modules/events/admin-notify.service.spec.ts (3 tests)

Total: 17 passed ✓
```

## Configuration

Переменные окружения, используемые при создании пользователя:
- `TRADING_EVENTS_ENABLED` — включать ли уведомления о создании пользователя
- `TRADING_EVENTS_HOST` — хост для уведомлений

## Behavior Flow

1. **Telegram login** → создание нового пользователя в локальной БД
2. **createUser()** вызывает:
   - `tradingUsersRepository.countAll()` — получить количество пользователей в trading DB
   - Если `count === 0` → права = `['admin']`
   - Если `count > 0` → права = `['view']`
3. **externalService.createUser()** — создать пользователя в API с соответствующими правами
4. **adminNotifyService.notifyUserCreated()** — отправить уведомление с флагом `isFirstUser`

## Error Handling

- Если `countAll()` выбросит исключение, пользователь создается с правами `['view']`
- Ошибка логируется в `logger.warn()`
- Процесс создания не прерывается

## Notes

- Логика срабатывает только при создании пользователя через Telegram (loginWithTelegram → createUser)
- Первого админского пользователя можно создать только при чистом деплое
- Если нужно создать дополнительного админа, требуется прямое обновление БД или API вызов с правами `['admin']`
