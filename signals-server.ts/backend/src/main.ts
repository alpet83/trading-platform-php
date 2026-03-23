import { NestFactory } from '@nestjs/core';
import { AppModule } from './app.module';
import { config } from 'dotenv';
import { Logger } from '@nestjs/common';
import { WebServerSetup } from '@wormsoft/nest-webserver';
import { singleLineMessage } from '@wormsoft/nest-common';
import { Env } from './config/env.validation';

const logger = new Logger('APP_BOOTSTRAP');

async function bootstrap() {
  const app = await NestFactory.create(AppModule);
  // nginx already publishes backend under ${APP_BASE_PATH}/api,
  // so Nest itself must keep /api (not /app/api and not /api/api)
  if (Env.API_PREFIX) {
    app.setGlobalPrefix(Env.API_PREFIX);
  }
  await app.get(WebServerSetup).setup(app);
}

process.on('uncaughtException', (err) => {
  if (err instanceof Error) {
    const reason = singleLineMessage(err);
    logger.error(`An uncaught exception: ${reason}`);
  } else {
    logger.error(`An uncaught exception: ${err}`);
  }
});

process.on('unhandledRejection', (err: unknown) => {
  if (err instanceof Error) {
    const reason = singleLineMessage(err);
    logger.error(`An unhandled rejection: ${reason}`);
  } else {
    logger.error(`An unhandled rejection: ${err}`);
  }
});
config();

bootstrap();
