import { Module } from '@nestjs/common';
import { EventsModule } from '@modules/events/events.module';
import { UserExternalController } from '@modules/user/external/user.external.controller';
import { UserExternalService } from '@modules/user/external/user.external.service';
import { TradingUsersModule } from '@modules/user/trading/trading-users.module';
import { AdminGuard } from '@common/auth/admin.guard';

@Module({
  imports: [EventsModule, TradingUsersModule],
  controllers: [UserExternalController],
  providers: [UserExternalService, AdminGuard],
  exports: [UserExternalService, AdminGuard],
})
export class UserExternalModule {}