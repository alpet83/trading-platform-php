import { Inject, Injectable } from '@nestjs/common';
import {
  EnabledFlag,
  SetupBaseGroup,
  TRADING_DB_CONNECTION_FACTORY,
  TradingDbConnection,
  TradingDbConnectionFactory,
  TradingUser,
  TradingUserCreateInput,
  TradingUserRight,
  TradingUserUpdateInput,
  TradingUsersRow,
  TradingWriteResult,
  VALID_TRADING_USER_RIGHTS,
} from './trading-users.types';

@Injectable()
export class TradingUsersRepository {
  constructor(
    @Inject(TRADING_DB_CONNECTION_FACTORY)
    private readonly connectionFactory: TradingDbConnectionFactory,
  ) {}

  async findAll(): Promise<TradingUser[]> {
    return this.withConnection(async (connection) => {
      const [rows] = await connection.execute<TradingUsersRow[]>(
        'SELECT chat_id, user_name, rights, enabled, base_setup FROM chat_users ORDER BY chat_id ASC',
      );

      return rows.map((row) => this.mapRow(row));
    });
  }

  async countAll(): Promise<number> {
    return this.withConnection(async (connection) => {
      const [rows] = await connection.execute<[{ count: number }]>(
        'SELECT COUNT(*) as count FROM chat_users',
      );

      return rows.length > 0 ? rows[0].count : 0;
    });
  }

  async getAdminUsers(): Promise<TradingUser[]> {
    return this.withConnection(async (connection) => {
      const [rows] = await connection.execute<TradingUsersRow[]>(
        "SELECT chat_id, user_name, rights, enabled, base_setup FROM chat_users WHERE enabled = 1 AND FIND_IN_SET('admin', rights) > 0 ORDER BY chat_id ASC",
      );

      return rows.map((row) => this.mapRow(row));
    });
  }

  async findByChatId(id: number): Promise<TradingUser | null> {
    return this.withConnection(async (connection) => {
      const [rows] = await connection.execute<TradingUsersRow[]>(
        'SELECT chat_id, user_name, rights, enabled, base_setup FROM chat_users WHERE chat_id = ? LIMIT 1',
        [id],
      );

      return rows.length > 0 ? this.mapRow(rows[0]) : null;
    });
  }

  async findByUserName(userName: string): Promise<TradingUser | null> {
    return this.withConnection(async (connection) => {
      const [rows] = await connection.execute<TradingUsersRow[]>(
        'SELECT chat_id, user_name, rights, enabled, base_setup FROM chat_users WHERE user_name = ? LIMIT 1',
        [userName],
      );

      return rows.length > 0 ? this.mapRow(rows[0]) : null;
    });
  }

  async create(payload: TradingUserCreateInput): Promise<TradingUser> {
    this.validatePayload(payload);

    return this.withConnection(async (connection) => {
      const baseSetup = await this.resolveCreateBaseSetup(
        connection,
        payload.base_setup,
      );

      await connection.execute<TradingWriteResult>(
        'INSERT INTO chat_users (chat_id, user_name, rights, enabled, base_setup) VALUES (?, ?, ?, ?, ?)',
        [
          payload.id,
          payload.user_name,
          this.serializeRights(payload.rights),
          payload.enabled,
          baseSetup,
        ],
      );

      const [rows] = await connection.execute<TradingUsersRow[]>(
        'SELECT chat_id, user_name, rights, enabled, base_setup FROM chat_users WHERE chat_id = ? LIMIT 1',
        [payload.id],
      );

      if (rows.length === 0) {
        throw new Error(`Trading user ${payload.id} was not found after insert`);
      }

      return this.mapRow(rows[0]);
    });
  }

  async updateRightsAndEnabled(
    id: number,
    rights: TradingUserRight[],
    enabled: EnabledFlag,
  ): Promise<TradingUser | null> {
    this.validateId(id);
    this.validateRights(rights);
    this.validateEnabled(enabled);

    return this.withConnection(async (connection) => {
      await connection.execute<TradingWriteResult>(
        'UPDATE chat_users SET rights = ?, enabled = ? WHERE chat_id = ?',
        [this.serializeRights(rights), enabled, id],
      );

      const [rows] = await connection.execute<TradingUsersRow[]>(
        'SELECT chat_id, user_name, rights, enabled, base_setup FROM chat_users WHERE chat_id = ? LIMIT 1',
        [id],
      );

      return rows.length > 0 ? this.mapRow(rows[0]) : null;
    });
  }

  async update(payload: TradingUserUpdateInput): Promise<TradingUser | null> {
    this.validatePayload(payload);

    return this.withConnection(async (connection) => {
      const [currentRows] = await connection.execute<TradingUsersRow[]>(
        'SELECT base_setup FROM chat_users WHERE chat_id = ? LIMIT 1',
        [payload.id],
      );

      if (currentRows.length === 0) {
        return null;
      }

      const baseSetup =
        payload.base_setup === undefined
          ? this.normalizeBaseSetup(currentRows[0].base_setup)
          : this.normalizeBaseSetup(payload.base_setup);

      await connection.execute<TradingWriteResult>(
        'UPDATE chat_users SET user_name = ?, rights = ?, enabled = ?, base_setup = ? WHERE chat_id = ?',
        [
          payload.user_name,
          this.serializeRights(payload.rights),
          payload.enabled,
          baseSetup,
          payload.id,
        ],
      );

      const [rows] = await connection.execute<TradingUsersRow[]>(
        'SELECT chat_id, user_name, rights, enabled, base_setup FROM chat_users WHERE chat_id = ? LIMIT 1',
        [payload.id],
      );

      return rows.length > 0 ? this.mapRow(rows[0]) : null;
    });
  }

  async deleteByChatId(id: number): Promise<boolean> {
    this.validateId(id);

    return this.withConnection(async (connection) => {
      const [result] = await connection.execute<TradingWriteResult>(
        'DELETE FROM chat_users WHERE chat_id = ?',
        [id],
      );

      return (result.affectedRows || 0) > 0;
    });
  }

  async findSetupBaseGroups(): Promise<SetupBaseGroup[]> {
    return this.withConnection(async (connection) => {
      const [rows] = await connection.execute<Array<{ base_setup: number; users_count: number }>>(
        'SELECT base_setup, COUNT(*) as users_count FROM chat_users GROUP BY base_setup ORDER BY base_setup ASC',
      );

      return rows.map((row) => ({
        base_setup: Number(row.base_setup),
        users_count: Number(row.users_count),
      }));
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

  private mapRow(row: TradingUsersRow): TradingUser {
    return {
      id: Number(row.chat_id),
      user_name: row.user_name,
      rights: this.parseRights(row.rights),
      enabled: this.toEnabledFlag(row.enabled),
      base_setup: this.normalizeBaseSetup(row.base_setup),
    };
  }

  private normalizeBaseSetup(value: number | null | undefined): number {
    const parsed = Number(value ?? 0);
    if (!Number.isFinite(parsed)) {
      return 0;
    }

    return Math.max(0, Math.trunc(parsed));
  }

  private async resolveCreateBaseSetup(
    connection: TradingDbConnection,
    explicitBaseSetup: number | undefined,
  ): Promise<number> {
    if (explicitBaseSetup !== undefined) {
      return this.normalizeBaseSetup(explicitBaseSetup);
    }

    const [rows] = await connection.execute<Array<{ count: number }>>(
      'SELECT COUNT(*) as count FROM chat_users',
    );

    const count = rows.length > 0 ? Number(rows[0].count) : 0;
    return Math.max(0, count) * 10;
  }

  private parseRights(rights: string | null): TradingUserRight[] {
    if (!rights) {
      return [];
    }

    return rights
      .split(',')
      .map((item) => item.trim())
      .filter((item): item is TradingUserRight =>
        VALID_TRADING_USER_RIGHTS.includes(item as TradingUserRight),
      );
  }

  private serializeRights(rights: TradingUserRight[]): string {
    return rights.join(',');
  }

  private validatePayload(payload: TradingUserCreateInput) {
    this.validateId(payload.id);

    if (!String(payload.user_name || '').trim()) {
      throw new Error('Trading user user_name must be a non-empty string');
    }

    this.validateRights(payload.rights);
    this.validateEnabled(payload.enabled);
  }

  private validateId(id: number) {
    if (!Number.isInteger(id) || id <= 0) {
      throw new Error('Trading user id must be a positive integer');
    }
  }

  private validateRights(rights: TradingUserRight[]) {
    if (!Array.isArray(rights)) {
      throw new Error('Trading user rights must be an array');
    }

    const invalid = rights.find(
      (item) => !VALID_TRADING_USER_RIGHTS.includes(item),
    );

    if (invalid) {
      throw new Error(`Invalid trading user right: ${invalid}`);
    }
  }

  private validateEnabled(enabled: number) {
    if (enabled !== 0 && enabled !== 1) {
      throw new Error('Trading user enabled flag must be 0 or 1');
    }
  }

  private toEnabledFlag(value: number): EnabledFlag {
    return value === 1 ? 1 : 0;
  }
}
