import { TradingUsersRepository } from './trading-users.repository';
import {
  TradingDbConnection,
  TradingUsersRow,
} from './trading-users.types';

describe('TradingUsersRepository', () => {
  const createConnectionMock = () => {
    const connection: jest.Mocked<TradingDbConnection> = {
      execute: jest.fn(),
      end: jest.fn().mockResolvedValue(undefined),
    };

    const factory = jest.fn().mockResolvedValue(connection);
    const repository = new TradingUsersRepository(factory);

    return { connection, factory, repository };
  };

  it('maps rows from findAll into API-friendly users', async () => {
    const { connection, repository } = createConnectionMock();
    const rows: TradingUsersRow[] = [
      {
        chat_id: 7,
        user_name: 'alice',
        rights: 'view,admin',
        enabled: 1,
      },
    ];

    connection.execute.mockResolvedValueOnce([rows, {} as unknown]);

    const users = await repository.findAll();

    expect(users).toEqual([
      {
        id: 7,
        user_name: 'alice',
        rights: ['view', 'admin'],
        enabled: 1,
      },
    ]);
    expect(connection.end).toHaveBeenCalledTimes(1);
  });

  it('creates a user and returns selected row', async () => {
    const { connection, repository } = createConnectionMock();
    const row: TradingUsersRow = {
      chat_id: 11,
      user_name: 'bob',
      rights: 'view,trade',
      enabled: 1,
    };

    connection.execute
      .mockResolvedValueOnce([{ affectedRows: 1 }, {} as unknown])
      .mockResolvedValueOnce([[row], {} as unknown]);

    const created = await repository.create({
      id: 11,
      user_name: 'bob',
      rights: ['view', 'trade'],
      enabled: 1,
    });

    expect(created).toEqual({
      id: 11,
      user_name: 'bob',
      rights: ['view', 'trade'],
      enabled: 1,
    });
    expect(connection.execute).toHaveBeenNthCalledWith(
      1,
      'INSERT INTO chat_users (chat_id, user_name, rights, enabled) VALUES (?, ?, ?, ?)',
      [11, 'bob', 'view,trade', 1],
    );
  });

  it('updates rights and enabled state', async () => {
    const { connection, repository } = createConnectionMock();
    const row: TradingUsersRow = {
      chat_id: 13,
      user_name: 'charlie',
      rights: 'admin',
      enabled: 0,
    };

    connection.execute
      .mockResolvedValueOnce([{ affectedRows: 1 }, {} as unknown])
      .mockResolvedValueOnce([[row], {} as unknown]);

    const updated = await repository.updateRightsAndEnabled(13, ['admin'], 0);

    expect(updated).toEqual({
      id: 13,
      user_name: 'charlie',
      rights: ['admin'],
      enabled: 0,
    });
    expect(connection.execute).toHaveBeenNthCalledWith(
      1,
      'UPDATE chat_users SET rights = ?, enabled = ? WHERE chat_id = ?',
      ['admin', 0, 13],
    );
  });

  it('updates user name, rights and enabled state', async () => {
    const { connection, repository } = createConnectionMock();
    const row: TradingUsersRow = {
      chat_id: 17,
      user_name: 'delta',
      rights: 'view,trade',
      enabled: 1,
    };

    connection.execute
      .mockResolvedValueOnce([{ affectedRows: 1 }, {} as unknown])
      .mockResolvedValueOnce([[row], {} as unknown]);

    const updated = await repository.update({
      id: 17,
      user_name: 'delta',
      rights: ['view', 'trade'],
      enabled: 1,
    });

    expect(updated).toEqual({
      id: 17,
      user_name: 'delta',
      rights: ['view', 'trade'],
      enabled: 1,
    });
    expect(connection.execute).toHaveBeenNthCalledWith(
      1,
      'UPDATE chat_users SET user_name = ?, rights = ?, enabled = ? WHERE chat_id = ?',
      ['delta', 'view,trade', 1, 17],
    );
  });

  it('returns delete status from affectedRows', async () => {
    const { connection, repository } = createConnectionMock();

    connection.execute.mockResolvedValueOnce([{ affectedRows: 1 }, {} as unknown]);

    await expect(repository.deleteByChatId(19)).resolves.toBe(true);
    expect(connection.execute).toHaveBeenCalledWith(
      'DELETE FROM chat_users WHERE chat_id = ?',
      [19],
    );
  });

  it('rejects invalid enabled flag before touching the DB', async () => {
    const { factory, repository } = createConnectionMock();

    await expect(
      repository.updateRightsAndEnabled(1, ['view'], 2 as 0 | 1),
    ).rejects.toThrow('Trading user enabled flag must be 0 or 1');
    expect(factory).not.toHaveBeenCalled();
  });

  it('counts total users in the system', async () => {
    const { connection, repository } = createConnectionMock();

    connection.execute.mockResolvedValueOnce([
      [{ count: 3 }],
      {} as unknown,
    ]);

    const count = await repository.countAll();

    expect(count).toBe(3);
    expect(connection.execute).toHaveBeenCalledWith(
      'SELECT COUNT(*) as count FROM chat_users',
    );
    expect(connection.end).toHaveBeenCalledTimes(1);
  });

  it('returns 0 when countAll finds no users', async () => {
    const { connection, repository } = createConnectionMock();

    connection.execute.mockResolvedValueOnce([
      [{ count: 0 }],
      {} as unknown,
    ]);

    const count = await repository.countAll();

    expect(count).toBe(0);
  });

  it('returns only enabled admin users', async () => {
    const { connection, repository } = createConnectionMock();
    const rows: TradingUsersRow[] = [
      {
        chat_id: 5,
        user_name: 'alice',
        rights: 'view,admin',
        enabled: 1,
      },
      {
        chat_id: 7,
        user_name: 'bob',
        rights: 'admin',
        enabled: 1,
      },
    ];

    connection.execute.mockResolvedValueOnce([rows, {} as unknown]);

    const users = await repository.getAdminUsers();

    expect(users).toEqual([
      {
        id: 5,
        user_name: 'alice',
        rights: ['view', 'admin'],
        enabled: 1,
      },
      {
        id: 7,
        user_name: 'bob',
        rights: ['admin'],
        enabled: 1,
      },
    ]);
    expect(connection.execute).toHaveBeenCalledWith(
      "SELECT chat_id, user_name, rights, enabled FROM chat_users WHERE enabled = 1 AND FIND_IN_SET('admin', rights) > 0 ORDER BY chat_id ASC",
    );
  });
});
