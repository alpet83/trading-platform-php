import { Module } from '@nestjs/common';
import { BotsService } from '@modules/bots/bots.service';
import { BotsController } from '@modules/bots/bots.controller';

@Module({
  providers: [BotsService],
  controllers: [BotsController],
})
export class BotsModule {}
