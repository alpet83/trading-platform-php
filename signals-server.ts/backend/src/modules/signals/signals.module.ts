import { Module } from '@nestjs/common';
import { SignalsService } from '@modules/signals/signals.service';
import { SignalsController } from '@modules/signals/signals.controller';
import { JwtModule } from '@nestjs/jwt';
import { PassportModule } from '@nestjs/passport';
import { JwtStrategy } from '@modules/jwt/jwt.strategy';
import { UserModule } from '@modules/user/user.module';

@Module({
  imports: [
    UserModule,
    PassportModule.register({ defaultStrategy: 'jwt' }),
    JwtModule.register({
      secret: process.env.JWT_SECRET || 'secretKey',
      signOptions: { expiresIn: '3h' },
    }),
  ],
  controllers: [SignalsController],
  providers: [SignalsService, JwtStrategy],
})
export class SignalsModule {}
