import { Inject, Injectable } from '@nestjs/common';
import {
  TRADING_DB_CONNECTION_FACTORY,
  TradingDbConnection,
  TradingDbConnectionFactory,
  TradingEventInput,
  TradingEventRecord,
  TradingHostRow,
  TradingInsertResult,
} from './trading-events.types';

@Injectable()
export class TradingEventsRepository {
  constructor(
    @Inject(TRADING_DB_CONNECTION_FACTORY)
    private readonly connectionFactory: TradingDbConnectionFactory,
  ) {}

  async resolveHostId(host: string): Promise<number> {
    const hostValue = String(host || '').trim();

    if (!hostValue) {
      return 9;
    }

    return this.withConnection(async (connection) => {
      const [rows] = await connection.execute<TradingHostRow[]>(
        'SELECT id FROM hosts WHERE name = ? OR ip = ? LIMIT 1',
        [hostValue, hostValue],
      );

      if (rows.length === 0) {
        return 9;
      }

      return Number(rows[0].id);
    });
  }

  async insertEvent(input: TradingEventInput): Promise<TradingEventRecord> {
    return this.withConnection(async (connection) => {
      const payload = {
        tag: String(input.tag || '').slice(0, 8),
        event: String(input.event || '').slice(0, 2048),
        value: Number.isFinite(input.value) ? input.value : 0,
        flags: Number.isFinite(input.flags) ? input.flags : 0,
        attach: input.attach ?? null,
        chatId: Number(input.chatId || 0),
        hostId: Number(input.hostId || 9),
      };

      const [result] = await connection.execute<TradingInsertResult>(
        'INSERT IGNORE INTO events (tag, host, event, value, flags, attach, chat) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [
          payload.tag,
          payload.hostId,
          payload.event,
          payload.value,
          payload.flags,
          payload.attach,
          payload.chatId,
        ],
      );

      return {
        id: Number(result.insertId || 0),
        tag: payload.tag,
        event: payload.event,
        value: payload.value,
        flags: payload.flags,
        chat: payload.chatId,
        host: payload.hostId,
      };
    });
  }

  private async withConnection<T>(
    operation: (connection: TradingDbConnection) => Promise<T>,
  ): Promise<T> {
    const connection = await this.connectionFactory();

    try {
      return await operation(connection);
    } finally {
      await connection.end();
    }
  }
}
