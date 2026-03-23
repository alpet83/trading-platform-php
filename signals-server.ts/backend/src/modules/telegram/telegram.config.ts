import { Injectable } from '@nestjs/common';
import { BaseConfig } from '@wormsoft/nest-common';
import { IsNotEmpty, IsString } from 'class-validator';
import { Env } from '../../config/env.validation';

@Injectable()
export class TelegramConfig extends BaseConfig {
  @IsNotEmpty()
  @IsString()
  readonly botToken: string = Env.BOT_TOKEN;
}
