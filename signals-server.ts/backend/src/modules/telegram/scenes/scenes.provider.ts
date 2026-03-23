import { Injectable } from '@nestjs/common';
import { TelegrafContext } from '@wormsoft/nest-telegram';
import { Message } from '@grammyjs/types';
import { ADMIN_SCENE } from '@modules/telegram/scenes/admin_interface/admin.scene';
import { ScenesConfig } from '@modules/telegram/scenes/config/scenes.config';

@Injectable()
export class ScenesProvider {
  constructor(private readonly config: ScenesConfig) {}

  public async sceneDefiner(ctx: TelegrafContext): Promise<string> {
    const chatSceneMap = {
      [this.config.adminChatId]: ADMIN_SCENE,
    };
    let currentScene;
    const chatId = ctx.chat.id;
    const isTopicMessage = ctx.message?.is_topic_message;
    if (chatId && chatSceneMap[chatId] && !isTopicMessage) {
      currentScene = chatSceneMap[chatId];
    } else {
      if (ctx.chat.id < 0) {
        if (isTopicMessage) {
          const topic = ctx.message.message_thread_id;
          if (this.config.dynamicSceneForTopicMap[topic]) {
            currentScene = this.config.dynamicSceneForTopicMap[topic];
          }
        }
      } else currentScene = '';
    }
    return currentScene;
  }

  public async sessionKeyDefiner(ctx: TelegrafContext): Promise<string> {
    let base = `${ctx.chat.id}:${ctx.from.id}`;
    const msg = ctx.msg;
    if (msg && (msg as Message).is_topic_message)
      base += (msg as Message).is_topic_message
        ? ':' + (msg as Message).message_thread_id
        : undefined;
    return base;
  }
}
