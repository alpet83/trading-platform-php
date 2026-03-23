import { AdminNotifyService } from './admin-notify.service';
import { TradingEventsRepository } from './trading-events.repository';
import { AuditLogService } from '@common/logging/audit-log.service';
import { TradingUsersRepository } from '@modules/user/trading/trading-users.repository';

describe('AdminNotifyService', () => {
  const createService = () => {
    const repository: jest.Mocked<TradingEventsRepository> = {
      resolveHostId: jest.fn(),
      insertEvent: jest.fn(),
    } as unknown as jest.Mocked<TradingEventsRepository>;

    const tradingUsersRepository: jest.Mocked<TradingUsersRepository> = {
      getAdminUsers: jest.fn(),
    } as unknown as jest.Mocked<TradingUsersRepository>;

    const auditLogService: jest.Mocked<AuditLogService> = {
      logRequest: jest.fn().mockResolvedValue(undefined),
      logError: jest.fn().mockResolvedValue(undefined),
    } as unknown as jest.Mocked<AuditLogService>;

    const service = new AdminNotifyService(
      repository,
      auditLogService,
      tradingUsersRepository,
    );

    return { service, repository, auditLogService, tradingUsersRepository };
  };

  afterEach(() => {
    delete process.env.TRADING_EVENTS_ENABLED;
  });

  it('emits event for each admin user when notifications are enabled', async () => {
    process.env.TRADING_EVENTS_ENABLED = '1';

    const { service, repository, auditLogService, tradingUsersRepository } =
      createService();

    tradingUsersRepository.getAdminUsers.mockResolvedValue([
      {
        id: 555,
        user_name: 'admin1',
        rights: ['admin'],
        enabled: 1,
      },
      {
        id: 777,
        user_name: 'admin2',
        rights: ['view', 'admin'],
        enabled: 1,
      },
    ]);
    repository.resolveHostId.mockResolvedValue(9);
    repository.insertEvent
      .mockResolvedValueOnce({
        id: 101,
        tag: 'LOGIN',
        event: 'ok',
        value: 0,
        flags: 0,
        chat: 555,
        host: 9,
      })
      .mockResolvedValueOnce({
        id: 102,
        tag: 'LOGIN',
        event: 'ok',
        value: 0,
        flags: 0,
        chat: 777,
        host: 9,
      });

    await service.notifyLoginSuccess({
      actorTelegramId: '1',
      targetTelegramId: '1',
      userName: 'admin',
    });

    expect(tradingUsersRepository.getAdminUsers).toHaveBeenCalled();
    expect(repository.resolveHostId).toHaveBeenCalled();
    expect(repository.insertEvent).toHaveBeenCalledTimes(2);
    expect(repository.insertEvent).toHaveBeenNthCalledWith(
      1,
      expect.objectContaining({ chatId: 555, hostId: 9, tag: 'LOGIN' }),
    );
    expect(repository.insertEvent).toHaveBeenNthCalledWith(
      2,
      expect.objectContaining({ chatId: 777, hostId: 9, tag: 'LOGIN' }),
    );
    expect(auditLogService.logRequest).toHaveBeenCalledTimes(2);
  });

  it('skips when notifications are disabled', async () => {
    process.env.TRADING_EVENTS_ENABLED = '0';

    const { service, repository, auditLogService, tradingUsersRepository } =
      createService();

    await service.notifyUserCreated({
      actorTelegramId: '1',
      targetTelegramId: '2',
      userName: 'new_user',
    });

    expect(tradingUsersRepository.getAdminUsers).not.toHaveBeenCalled();
    expect(repository.resolveHostId).not.toHaveBeenCalled();
    expect(repository.insertEvent).not.toHaveBeenCalled();
    expect(auditLogService.logRequest).not.toHaveBeenCalled();
  });

  it('writes error audit on repository failure', async () => {
    process.env.TRADING_EVENTS_ENABLED = '1';

    const { service, repository, auditLogService, tradingUsersRepository } =
      createService();

    tradingUsersRepository.getAdminUsers.mockResolvedValue([
      {
        id: 555,
        user_name: 'admin1',
        rights: ['admin'],
        enabled: 1,
      },
    ]);
    repository.resolveHostId.mockResolvedValue(9);
    repository.insertEvent.mockRejectedValue(new Error('db down'));

    await service.notifyUserDeleted({
      actorTelegramId: '1',
      targetTelegramId: '2',
    });

    expect(auditLogService.logError).toHaveBeenCalled();
  });

  it('skips event insert when there are no admin users', async () => {
    process.env.TRADING_EVENTS_ENABLED = '1';

    const { service, repository, auditLogService, tradingUsersRepository } =
      createService();

    tradingUsersRepository.getAdminUsers.mockResolvedValue([]);

    await service.notifyUserUpdated({
      actorTelegramId: '1',
      targetTelegramId: '3',
    });

    expect(repository.resolveHostId).not.toHaveBeenCalled();
    expect(repository.insertEvent).not.toHaveBeenCalled();
    expect(auditLogService.logRequest).not.toHaveBeenCalled();
  });
});
