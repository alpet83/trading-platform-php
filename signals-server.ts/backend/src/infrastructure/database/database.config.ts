import { BaseConfig } from '@wormsoft/nest-common';
import { IsNotEmpty, IsString } from 'class-validator';
import { Env } from '../../config/env.validation';

export class DatabaseConfig extends BaseConfig {
  @IsNotEmpty()
  @IsString()
  readonly url: string = Env.DATABASE_URL;
}
