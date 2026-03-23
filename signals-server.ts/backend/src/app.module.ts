import { MiddlewareConsumer, Module, NestModule } from '@nestjs/common';
import { DatabaseModule } from '@infrastructure/database/database.module';
import { WebServerModule } from '@wormsoft/nest-webserver';
import { SignalsModule } from '@modules/signals/signals.module';
import { ConfigModule } from '@nestjs/config';
import { Env } from './config/env.validation';
import { LoggerMiddleware } from '@common/middleware/logger.middleware';
import { UserModule } from '@modules/user/user.module';
import { ChartModule } from '@modules/chart/chart.module';
import { StatsModule } from '@modules/stats/stats.module';
import { BotsModule } from '@modules/bots/bots.module';
import { RequestIdMiddleware } from '@common/middleware/request-id.middleware';
import { LoggingModule } from '@common/logging/logging.module';

@Module({
  imports: [
    ConfigModule.forRoot({
      isGlobal: true,
    }),
    DatabaseModule.forRoot(),
    SignalsModule,
    BotsModule,
    StatsModule,
    ChartModule,
    LoggingModule,
    WebServerModule.register(Env.PORT, Env.SWAGGER_PATH),
    UserModule,
  ],
  controllers: [],
  providers: [],
})
export class AppModule implements NestModule {
  configure(consumer: MiddlewareConsumer) {
    consumer.apply(RequestIdMiddleware, LoggerMiddleware).forRoutes('*');
  }
}
