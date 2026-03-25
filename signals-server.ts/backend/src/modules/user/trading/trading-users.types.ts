export const VALID_TRADING_USER_RIGHTS = ['view', 'trade', 'admin'] as const;

export type TradingUserRight = (typeof VALID_TRADING_USER_RIGHTS)[number];

export type EnabledFlag = 0 | 1;

export interface TradingUser {
  id: number;
  user_name: string;
  rights: TradingUserRight[];
  enabled: EnabledFlag;
  base_setup: number;
}

export interface TradingUserCreateInput {
  id: number;
  user_name: string;
  rights: TradingUserRight[];
  enabled: EnabledFlag;
  base_setup?: number;
}

export interface TradingUserUpdateInput {
  id: number;
  user_name: string;
  rights: TradingUserRight[];
  enabled: EnabledFlag;
  base_setup?: number;
}

export interface TradingUsersRow {
  chat_id: number;
  user_name: string;
  rights: string | null;
  enabled: number;
  base_setup?: number | null;
}

export interface SetupBaseGroup {
  base_setup: number;
  users_count: number;
}

export interface TradingWriteResult {
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
