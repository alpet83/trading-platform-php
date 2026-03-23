import { Injectable } from '@nestjs/common';
import { BaseConfig } from '@wormsoft/nest-common';
import { IsNumber, IsOptional } from 'class-validator';

@Injectable()
export class ScenesConfig extends BaseConfig {
  @IsNumber()
  @IsOptional()
  adminChatId = parseInt(process.env.ADMIN_CHAT_ID) || 0;

  public dynamicConfig = {
    requestsTopicId: 0,
  };

  public dynamicSceneForTopicMap: Record<number, string> = {};
}
