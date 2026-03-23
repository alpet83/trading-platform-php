import { Module } from '@nestjs/common';
import { EventsModule } from '@modules/events/events.module';
import { UserExternalController } from '@modules/user/external/user.external.controller';
import { UserExternalService } from '@modules/user/external/user.external.service';
import { TradingUsersModule } from '@modules/user/trading/trading-users.module';

@Module({
  imports: [EventsModule, TradingUsersModule],
  controllers: [UserExternalController],
  providers: [UserExternalService],
  exports: [UserExternalService],
})
export class UserExternalModule {}
