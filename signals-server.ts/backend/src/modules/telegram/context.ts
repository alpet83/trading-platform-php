import { TelegrafContext } from '@wormsoft/nest-telegram';
import { Scenes } from 'telegraf';

export interface TGContext extends TelegrafContext {
  session: any;
  scene: Scenes.SceneContextScene<any>;
}
