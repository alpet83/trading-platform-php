import { Scene } from 'nestjs-telegraf';
import { Logger } from '@nestjs/common';

export const ADMIN_SCENE = 'admin_scene';

@Scene(ADMIN_SCENE)
export class AdminScene {
  private readonly logger = new Logger(this.constructor.name);
}
