export interface TradingEventInput {
  tag: string;
  event: string;
  value: number;
  flags: number;
  attach?: Buffer | null;
  chatId: number;
  hostId: number;
}

export interface TradingEventRecord {
  id: number;
  tag: string;
  event: string;
  value: number;
  flags: number;
  chat: number;
  host: number;
}

export interface TradingHostRow {
  id: number;
}

export interface TradingInsertResult {
  insertId?: number;
  affectedRows?: number;
}

export interface TradingDbConnection {
  execute<T = unknown>(
    sql: string,
    params?: readonly unknown[],
  ): Promise<[T, unknown]>;
  end(): Promise<void>;
}

export type TradingDbConnectionFactory = () => Promise<TradingDbConnection>;

export const TRADING_DB_CONNECTION_FACTORY = 'TRADING_DB_CONNECTION_FACTORY';
