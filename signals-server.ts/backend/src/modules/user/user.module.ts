import { Module } from '@nestjs/common';
import { UserService } from '@modules/user/user.service';
import { JwtModule } from '@nestjs/jwt';
import { UserController } from '@modules/user/user.controller';
import { UserExternalModule } from '@modules/user/external/user.external.module';
import { TradingUsersModule } from '@modules/user/trading/trading-users.module';
import { EventsModule } from '@modules/events/events.module';

@Module({
  imports: [
    EventsModule,
    UserExternalModule,
    TradingUsersModule,
    JwtModule.register({
      secret: process.env.JWT_SECRET || 'secretKey',
      signOptions: { expiresIn: '7d' },
    }),
  ],
  controllers: [UserController],
  providers: [UserService],
  exports: [UserService],
})
export class UserModule {}
