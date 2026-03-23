import { Global, Module } from '@nestjs/common';
import { ScenesConfig } from '@modules/telegram/scenes/config/scenes.config';

@Global()
@Module({
  providers: [ScenesConfig],
  exports: [ScenesConfig],
})
export class ScenesConfigModule {}
