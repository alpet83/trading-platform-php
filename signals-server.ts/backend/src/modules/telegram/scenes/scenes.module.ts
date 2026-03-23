import { Module } from '@nestjs/common';
import { ScenesProvider } from '@modules/telegram/scenes/scenes.provider';
import { AdminModule } from '@modules/telegram/scenes/admin_interface/admin.module';
import { ScenesConfigModule } from '@modules/telegram/scenes/config/config.module';

@Module({
  imports: [AdminModule, ScenesConfigModule],
  providers: [ScenesProvider],
  exports: [ScenesProvider],
})
export class ScenesModule {}
