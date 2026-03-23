import { Module } from '@nestjs/common';
import { StatsService } from '@modules/stats/stats.service';
import { StatsController } from '@modules/stats/stats.controller';

@Module({
  imports: [],
  controllers: [StatsController],
  providers: [StatsService],
})
export class StatsModule {}
