import { Logger, Module } from '@nestjs/common';
import { ScenesModule } from '@modules/telegram/scenes/scenes.module';
import { ScenesProvider } from '@modules/telegram/scenes/scenes.provider';
import { PostgresStore } from '@modules/telegram/telegram.store';
import { TelegrafContext, TelegramModule } from '@wormsoft/nest-telegram';
import { TelegramConfig } from '@modules/telegram/telegram.config';

@Module({
  imports: [
    TelegramModule.registerAsync({
      imports: [
        ScenesModule,
        {
          module: class PostgresProvider {},
          providers: [TelegramConfig, PostgresStore],
          exports: [TelegramConfig, PostgresStore],
        },
      ],
      useFactory: (
        config: TelegramConfig,
        provider: ScenesProvider,
        store: PostgresStore,
      ) => {
        return {
          botToken: config.botToken,
          sceneDefiner: (ctx: TelegrafContext) => provider.sceneDefiner(ctx),
          sessionStore: store,
          sessionKeyDefiner: (ctx: TelegrafContext) =>
            provider.sessionKeyDefiner(ctx),
          middlewaresBefore: [
            async (ctx: TelegrafContext, next) => {
              new Logger('TelegramUpdate').log(
                `New update:: ${JSON.stringify(ctx.update)}`,
              );
              await next();
            },
          ],
        };
      },
      inject: [TelegramConfig, ScenesProvider, PostgresStore],
    }),
  ],
})
export class TelegramInstallerModule {}
