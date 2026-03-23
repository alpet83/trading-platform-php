import { Module } from '@nestjs/common';
import { ChartController } from '@modules/chart/chart.controller';
import { ChartService } from '@modules/chart/chart.service';

@Module({
  controllers: [ChartController],
  providers: [ChartService],
})
export class ChartModule {}
