import { Module } from '@nestjs/common';
import { createConnection } from 'mysql2/promise';
import { Env, sanitizeDbUrl } from '../../../config/env.validation';
import { TradingUsersRepository } from './trading-users.repository';
import { TRADING_DB_CONNECTION_FACTORY } from './trading-users.types';

@Module({
  providers: [
    {
      provide: TRADING_DB_CONNECTION_FACTORY,
      useFactory: () => {
        return () => createConnection(sanitizeDbUrl(Env.TRADING_DB_AUTH_URL));
      },
    },
    TradingUsersRepository,
  ],
  exports: [TradingUsersRepository],
})
export class TradingUsersModule {}
