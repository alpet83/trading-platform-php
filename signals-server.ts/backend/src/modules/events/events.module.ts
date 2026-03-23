import { Module } from '@nestjs/common';
import { createConnection } from 'mysql2/promise';
import { LoggingModule } from '@common/logging/logging.module';
import { Env, sanitizeDbUrl } from '../../config/env.validation';
import { AdminNotifyService } from './admin-notify.service';
import { TradingEventsRepository } from './trading-events.repository';
import { TRADING_DB_CONNECTION_FACTORY } from './trading-events.types';
import { TradingUsersModule } from '@modules/user/trading/trading-users.module';

@Module({
  imports: [LoggingModule, TradingUsersModule],
  providers: [
    {
      provide: TRADING_DB_CONNECTION_FACTORY,
      useFactory: () => {
        return () => createConnection(sanitizeDbUrl(Env.TRADING_DB_AUTH_URL));
      },
    },
    TradingEventsRepository,
    AdminNotifyService,
  ],
  exports: [AdminNotifyService, TradingEventsRepository],
})
export class EventsModule {}
