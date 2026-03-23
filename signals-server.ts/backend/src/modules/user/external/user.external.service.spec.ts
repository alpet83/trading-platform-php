// ---------------------------------------------------------------------------
// UserExternalDbService spec
// ---------------------------------------------------------------------------
describe('UserExternalDbService', () => {
  const loadService = () => {
    jest.resetModules();

    jest.doMock('@modules/events/admin-notify.service', () => ({
      AdminNotifyService: class AdminNotifyService {},
    }), { virtual: true });

    jest.doMock('@modules/user/external/user.external.dto', () => ({
      CreateUserDTO: class CreateUserDTO {},
      UpdateUserDTO: class UpdateUserDTO {},
    }), { virtual: true });

    jest.doMock('@modules/user/trading/trading-users.repository', () => ({
      TradingUsersRepository: class TradingUsersRepository {},
    }), { virtual: true });

    jest.doMock('@modules/user/trading/trading-users.types', () => ({
      VALID_TRADING_USER_RIGHTS: ['view', 'trade', 'admin'],
    }), { virtual: true });

    const { UserExternalDbService } = require('./user.external.db.service');
    return { UserExternalDbService };
  };

  afterEach(() => {
    jest.resetModules();
    jest.clearAllMocks();
  });

  const makeRepo = (overrides = {}) => ({
    findAll: jest.fn(),
    findByChatId: jest.fn(),
    findByUserName: jest.fn(),
    create: jest.fn(),
    deleteByChatId: jest.fn(),
    update: jest.fn(),
    ...overrides,
  });

  const makeNotify = () => ({
    notifyUserCreated: jest.fn().mockResolvedValue(undefined),
    notifyUserUpdated: jest.fn().mockResolvedValue(undefined),
    notifyUserDeleted: jest.fn().mockResolvedValue(undefined),
  });

  // --- getUsers ---
  it('getUsers returns mapped users from repository', async () => {
    const { UserExternalDbService } = loadService();
    const repo = makeRepo({
      findAll: jest.fn().mockResolvedValue([
        { id: 1, user_name: 'alice', rights: ['admin'], enabled: 1 },
      ]),
    });

    const service = new UserExternalDbService(makeNotify(), repo);

    await expect(service.getUsers({ telegramId: '1' })).resolves.toEqual([
      { id: 1, user_name: 'alice', rights: ['admin'], enabled: 1 },
    ]);
    expect(repo.findAll).toHaveBeenCalledTimes(1);
  });

  // --- createUser ---
  it('createUser creates a new user and notifies', async () => {
    const { UserExternalDbService } = loadService();
    const notify = makeNotify();
    const repo = makeRepo({
      findByChatId: jest.fn().mockResolvedValue(null),
      findByUserName: jest.fn().mockResolvedValue(null),
      create: jest.fn().mockResolvedValue({
        id: 7,
        user_name: 'bob',
        rights: ['view', 'trade'],
        enabled: 1,
      }),
    });

    const service = new UserExternalDbService(notify, repo);

    await expect(
      service.createUser(
        { id: '7', user_name: 'bob', rights: ['view', 'trade'], enabled: 1 },
        { telegramId: '99' },
      ),
    ).resolves.toEqual({
      ok: true,
      reason: 'created',
      id: 7,
      user: { id: 7, user_name: 'bob', rights: ['view', 'trade'], enabled: 1 },
    });

    expect(repo.create).toHaveBeenCalledWith({
      id: 7, user_name: 'bob', rights: ['view', 'trade'], enabled: 1,
    });
    expect(notify.notifyUserCreated).toHaveBeenCalledWith(
      expect.objectContaining({
        targetTelegramId: '7',
        userName: 'bob',
        meta: { source: 'user.external.createUser.ts' },
      }),
    );
  });

  it('createUser returns already_exists when user is found', async () => {
    const { UserExternalDbService } = loadService();
    const notify = makeNotify();
    const repo = makeRepo({
      findByChatId: jest.fn().mockResolvedValue({
        id: 7, user_name: 'bob', rights: ['view'], enabled: 1,
      }),
    });

    const service = new UserExternalDbService(notify, repo);

    await expect(
      service.createUser(
        { id: '7', user_name: 'bob', rights: ['view'], enabled: 1 },
        { telegramId: '99' },
      ),
    ).resolves.toEqual({
      ok: true,
      reason: 'already_exists',
      id: 7,
      user: { id: 7, user_name: 'bob', rights: ['view'], enabled: 1 },
    });

    expect(repo.create).not.toHaveBeenCalled();
    expect(notify.notifyUserCreated).not.toHaveBeenCalled();
  });

  // --- updateUser ---
  it('updateUser updates user_name, rights, enabled and notifies', async () => {
    const { UserExternalDbService } = loadService();
    const notify = makeNotify();
    const repo = makeRepo({
      findByChatId: jest.fn().mockResolvedValue({
        id: 9, user_name: 'old-name', rights: ['view'], enabled: 1,
      }),
      findByUserName: jest.fn().mockResolvedValue(null),
      update: jest.fn().mockResolvedValue({
        id: 9, user_name: 'new-name', rights: ['admin'], enabled: 1,
      }),
    });

    const service = new UserExternalDbService(notify, repo);

    await expect(
      service.updateUser(
        { telegramId: '99' },
        { id: '9', user_name: 'new-name', rights: ['admin'], enabled: 1 },
      ),
    ).resolves.toEqual({
      ok: true,
      reason: 'updated',
      id: 9,
      user: { id: 9, user_name: 'new-name', rights: ['admin'], enabled: 1 },
    });

    expect(repo.update).toHaveBeenCalledWith({
      id: 9, user_name: 'new-name', rights: ['admin'], enabled: 1,
    });
    expect(notify.notifyUserUpdated).toHaveBeenCalled();
  });

  // --- deleteUser ---
  it('deleteUser returns not_found when user is missing', async () => {
    const { UserExternalDbService } = loadService();
    const notify = makeNotify();
    const repo = makeRepo({
      findByChatId: jest.fn().mockResolvedValue(null),
    });

    const service = new UserExternalDbService(notify, repo);

    await expect(service.deleteUser({ telegramId: '99' }, '404')).resolves.toEqual({
      ok: false,
      reason: 'not_found',
      id: 404,
      user: null,
    });

    expect(repo.deleteByChatId).not.toHaveBeenCalled();
    expect(notify.notifyUserDeleted).not.toHaveBeenCalled();
  });
});

// ---------------------------------------------------------------------------
// UserExternalPhpService spec
// ---------------------------------------------------------------------------
describe('UserExternalPhpService', () => {
  const loadService = () => {
    jest.resetModules();

    jest.doMock('../../../config/env.validation', () => ({
      Env: {
        SIGNALS_API_URL: 'http://signals.local',
        AUTH_TOKEN: 'test-token',
      },
    }));

    jest.doMock('@modules/events/admin-notify.service', () => ({
      AdminNotifyService: class AdminNotifyService {},
    }), { virtual: true });

    jest.doMock('@modules/user/external/user.external.dto', () => ({
      CreateUserDTO: class CreateUserDTO {},
      UpdateUserDTO: class UpdateUserDTO {},
    }), { virtual: true });

    jest.doMock('@modules/user/trading/trading-users.types', () => ({
      VALID_TRADING_USER_RIGHTS: ['view', 'trade', 'admin'],
    }), { virtual: true });

    const fetchMock = jest.fn();
    jest.doMock('node-fetch', () => ({ __esModule: true, default: fetchMock }));

    const { UserExternalPhpService } = require('./user.external.php.service');
    return { UserExternalPhpService, fetchMock };
  };

  afterEach(() => {
    jest.resetModules();
    jest.clearAllMocks();
    jest.dontMock('../../../config/env.validation');
    jest.dontMock('node-fetch');
  });

  const makeNotify = () => ({
    notifyUserCreated: jest.fn().mockResolvedValue(undefined),
    notifyUserUpdated: jest.fn().mockResolvedValue(undefined),
    notifyUserDeleted: jest.fn().mockResolvedValue(undefined),
  });

  it('getUsers calls PHP API and returns result', async () => {
    const { UserExternalPhpService, fetchMock } = loadService();
    fetchMock.mockResolvedValue({
      status: 200,
      json: jest.fn().mockResolvedValue([{ id: 5, rights: ['view'] }]),
      text: jest.fn(),
    });

    const service = new UserExternalPhpService(makeNotify());

    await expect(service.getUsers({ telegramId: '5' })).resolves.toEqual([
      { id: 5, rights: ['view'] },
    ]);
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });
});

// ---------------------------------------------------------------------------
// UserExternalService facade spec
// ---------------------------------------------------------------------------
describe('UserExternalService (facade)', () => {
  const loadFacade = (usersApiSource: 'php' | 'ts') => {
    jest.resetModules();

    jest.doMock('../../../config/env.validation', () => ({
      Env: {
        SIGNALS_API_URL: 'http://signals.local',
        AUTH_TOKEN: 'test-token',
        USERS_API_SOURCE: usersApiSource,
      },
    }));

    jest.doMock('@modules/events/admin-notify.service', () => ({
      AdminNotifyService: class AdminNotifyService {},
    }), { virtual: true });

    jest.doMock('@modules/user/external/user.external.dto', () => ({
      CreateUserDTO: class CreateUserDTO {},
      UpdateUserDTO: class UpdateUserDTO {},
    }), { virtual: true });

    jest.doMock('@modules/user/trading/trading-users.repository', () => ({
      TradingUsersRepository: class TradingUsersRepository {},
    }), { virtual: true });

    jest.doMock('@modules/user/trading/trading-users.types', () => ({
      VALID_TRADING_USER_RIGHTS: ['view', 'trade', 'admin'],
    }), { virtual: true });

    const fetchMock = jest.fn();
    jest.doMock('node-fetch', () => ({ __esModule: true, default: fetchMock }));

    const { UserExternalService } = require('./user.external.service');
    return { UserExternalService, fetchMock };
  };

  afterEach(() => {
    jest.resetModules();
    jest.clearAllMocks();
    jest.dontMock('../../../config/env.validation');
    jest.dontMock('node-fetch');
  });

  it('facade picks DbService when USERS_API_SOURCE=ts', async () => {
    const { UserExternalService, fetchMock } = loadFacade('ts');
    const notify = {
      notifyUserCreated: jest.fn(),
      notifyUserUpdated: jest.fn(),
      notifyUserDeleted: jest.fn(),
    };
    const repo = {
      findAll: jest.fn().mockResolvedValue([
        { id: 1, user_name: 'alice', rights: ['admin'], enabled: 1 },
      ]),
      findByChatId: jest.fn(),
      findByUserName: jest.fn(),
      create: jest.fn(),
      deleteByChatId: jest.fn(),
      update: jest.fn(),
    };

    const service = new UserExternalService(notify, repo);
    await expect(service.getUsers({ telegramId: '1' })).resolves.toEqual([
      { id: 1, user_name: 'alice', rights: ['admin'], enabled: 1 },
    ]);
    expect(repo.findAll).toHaveBeenCalledTimes(1);
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('facade picks PhpService when USERS_API_SOURCE=php', async () => {
    const { UserExternalService, fetchMock } = loadFacade('php');
    fetchMock.mockResolvedValue({
      status: 200,
      json: jest.fn().mockResolvedValue([{ id: 5, rights: ['view'] }]),
      text: jest.fn(),
    });
    const notify = {
      notifyUserCreated: jest.fn(),
      notifyUserUpdated: jest.fn(),
      notifyUserDeleted: jest.fn(),
    };
    const repo = {
      findAll: jest.fn(),
      findByChatId: jest.fn(),
      findByUserName: jest.fn(),
      create: jest.fn(),
      deleteByChatId: jest.fn(),
      update: jest.fn(),
    };

    const service = new UserExternalService(notify, repo);
    await expect(service.getUsers({ telegramId: '5' })).resolves.toEqual([
      { id: 5, rights: ['view'] },
    ]);
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(repo.findAll).not.toHaveBeenCalled();
  });
});
