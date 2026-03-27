import { Injectable } from '@nestjs/common';
import { createConnection, ResultSetHeader, RowDataPacket } from 'mysql2/promise';
import { Env, sanitizeDbUrl } from '../../config/env.validation';

export interface InstanceHostRecord {
  host_id: number;
  host_name: string;
  instance_url: string;
  is_active: boolean;
}

interface InstanceHostRow extends RowDataPacket {
  host_id?: number | string;
  host_name?: string;
  instance_url?: string;
  is_active?: number | boolean;
}

@Injectable()
export class InstanceHostsRepository {
  async list(): Promise<InstanceHostRecord[]> {
    return this.withConnection(async (connection) => {
      const [rows] = await connection.execute<InstanceHostRow[]>(
        'SELECT host_id, host_name, instance_url, is_active FROM bot_hosts ORDER BY host_id ASC',
      );
      return rows.map((row) => this.mapRow(row));
    });
  }

  async findById(hostId: number): Promise<InstanceHostRecord | null> {
    return this.withConnection(async (connection) => {
      const [rows] = await connection.execute<InstanceHostRow[]>(
        'SELECT host_id, host_name, instance_url, is_active FROM bot_hosts WHERE host_id = ? LIMIT 1',
        [hostId],
      );
      return rows.length > 0 ? this.mapRow(rows[0]) : null;
    });
  }

  async findActive(): Promise<InstanceHostRecord | null> {
    return this.withConnection(async (connection) => {
      const [rows] = await connection.execute<InstanceHostRow[]>(
        'SELECT host_id, host_name, instance_url, is_active FROM bot_hosts WHERE is_active = 1 LIMIT 1',
      );
      return rows.length > 0 ? this.mapRow(rows[0]) : null;
    });
  }

  async create(payload: {
    host_name: string;
    instance_url: string;
    is_active?: boolean;
  }): Promise<InstanceHostRecord> {
    const hostName = this.normalizeHostName(payload.host_name);
    const instanceUrl = this.normalizeInstanceUrl(payload.instance_url);
    const isActive = Boolean(payload.is_active);

    return this.withConnection(async (connection) => {
      if (isActive) {
        await connection.execute('UPDATE bot_hosts SET is_active = 0');
      }

      const [result] = await connection.execute<ResultSetHeader>(
        'INSERT INTO bot_hosts (host_name, instance_url, is_active) VALUES (?, ?, ?)',
        [hostName, instanceUrl, isActive ? 1 : 0],
      );
      const insertedId = Number(result.insertId || 0);
      if (!Number.isInteger(insertedId) || insertedId <= 0) {
        throw new Error('Unable to resolve new bot host id after insert');
      }
      const created = await this.findById(insertedId);
      if (!created) {
        throw new Error(`Unable to load created bot host (id=${insertedId})`);
      }
      return created;
    });
  }

  async update(
    hostId: number,
    payload: {
      host_name: string;
      instance_url: string;
      is_active?: boolean;
    },
  ): Promise<InstanceHostRecord | null> {
    const hostName = this.normalizeHostName(payload.host_name);
    const instanceUrl = this.normalizeInstanceUrl(payload.instance_url);
    const isActive = Boolean(payload.is_active);

    return this.withConnection(async (connection) => {
      const [existing] = await connection.execute<InstanceHostRow[]>(
        'SELECT host_id FROM bot_hosts WHERE host_id = ? LIMIT 1',
        [hostId],
      );
      if (existing.length === 0) {
        return null;
      }

      if (isActive) {
        await connection.execute('UPDATE bot_hosts SET is_active = 0');
      }

      await connection.execute(
        'UPDATE bot_hosts SET host_name = ?, instance_url = ?, is_active = ? WHERE host_id = ?',
        [hostName, instanceUrl, isActive ? 1 : 0, hostId],
      );

      return this.findById(hostId);
    });
  }

  async activate(hostId: number): Promise<InstanceHostRecord | null> {
    return this.withConnection(async (connection) => {
      const [existing] = await connection.execute<InstanceHostRow[]>(
        'SELECT host_id FROM bot_hosts WHERE host_id = ? LIMIT 1',
        [hostId],
      );
      if (existing.length === 0) {
        return null;
      }

      await connection.execute('UPDATE bot_hosts SET is_active = 0');
      await connection.execute('UPDATE bot_hosts SET is_active = 1 WHERE host_id = ?', [hostId]);
      return this.findById(hostId);
    });
  }

  async delete(hostId: number): Promise<boolean> {
    return this.withConnection(async (connection) => {
      const [result] = await connection.execute<ResultSetHeader>(
        'DELETE FROM bot_hosts WHERE host_id = ?',
        [hostId],
      );
      return Number(result.affectedRows || 0) > 0;
    });
  }

  private mapRow(row: InstanceHostRow): InstanceHostRecord {
    return {
      host_id: Number(row.host_id),
      host_name: String(row.host_name || ''),
      instance_url: this.normalizeInstanceUrl(String(row.instance_url || '')),
      is_active: this.toBool(row.is_active),
    };
  }

  private toBool(value: unknown): boolean {
    if (typeof value === 'boolean') {
      return value;
    }
    return Number(value || 0) === 1;
  }

  private normalizeHostName(name: string): string {
    const value = String(name || '').trim();
    if (!value) {
      throw new Error('bot_hosts.host_name must be non-empty');
    }
    return value;
  }

  private normalizeInstanceUrl(url: string): string {
    const value = String(url || '').trim().replace(/\/+$/, '');
    if (!value) {
      throw new Error('bot_hosts.instance_url must be non-empty');
    }
    return value;
  }

  private async withConnection<T>(
    operation: (connection: Awaited<ReturnType<typeof createConnection>>) => Promise<T>,
  ): Promise<T> {
    const connection = await createConnection(sanitizeDbUrl(Env.TRADING_DB_AUTH_URL));
    try {
      return await operation(connection);
    } finally {
      await connection.end();
    }
  }
}