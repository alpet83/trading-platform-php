import { Controller, Get, Query } from '@nestjs/common';
import { ChartService } from '@modules/chart/chart.service';

@Controller('chart')
export class ChartController {
  constructor(private service: ChartService) {}
  @Get()
  async getChartData(
    @Query('exchange') exchange: string,
    @Query('account_id') account_id: string,
  ) {
    return await this.service.getChartData(exchange, account_id);
  }
}
