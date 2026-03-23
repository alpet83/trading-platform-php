import { TradingEventsRepository } from './trading-events.repository';
import {
  TradingDbConnection,
  TradingHostRow,
} from './trading-events.types';

describe('TradingEventsRepository', () => {
  const createRepository = () => {
    const connection: jest.Mocked<TradingDbConnection> = {
      execute: jest.fn(),
      end: jest.fn().mockResolvedValue(undefined),
    };

    const factory = jest.fn().mockResolvedValue(connection);
    const repository = new TradingEventsRepository(factory);

    return { connection, factory, repository };
  };

  it('resolves host id by name/ip', async () => {
    const { connection, repository } = createRepository();
    const rows: TradingHostRow[] = [{ id: 5 }];

    connection.execute.mockResolvedValueOnce([rows, {} as unknown]);

    await expect(repository.resolveHostId('backend-01')).resolves.toBe(5);
    expect(connection.execute).toHaveBeenCalledWith(
      'SELECT id FROM hosts WHERE name = ? OR ip = ? LIMIT 1',
      ['backend-01', 'backend-01'],
    );
  });

  it('inserts event into events table', async () => {
    const { connection, repository } = createRepository();

    connection.execute.mockResolvedValueOnce([
      { insertId: 77, affectedRows: 1 },
      {} as unknown,
    ]);

    const result = await repository.insertEvent({
      tag: 'AUTH',
      event: 'login success',
      value: 0,
      flags: 0,
      chatId: 500,
      hostId: 9,
    });

    expect(result).toEqual({
      id: 77,
      tag: 'AUTH',
      event: 'login success',
      value: 0,
      flags: 0,
      chat: 500,
      host: 9,
    });

    expect(connection.execute).toHaveBeenCalledWith(
      'INSERT IGNORE INTO events (tag, host, event, value, flags, attach, chat) VALUES (?, ?, ?, ?, ?, ?, ?)',
      ['AUTH', 9, 'login success', 0, 0, null, 500],
    );
  });
});
