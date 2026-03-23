import {
  Controller,
  Get,
  Query,
  Post,
  Body,
  Param,
  UseGuards,
} from '@nestjs/common';
import { StatsService } from '@modules/stats/stats.service';
import {
  CancelOrderRequestDto,
  UpdateOffsetRequestDto,
  UpdatePositionCoefRequestDto,
  UpdateTradeEnabledRequestDto,
} from '@modules/stats/stats.dto';
import { JwtAuthGuard } from '@modules/jwt/jwt-auth.guard';

@Controller('/stats')
@UseGuards(JwtAuthGuard)
export class StatsController {
  constructor(private readonly statsService: StatsService) {}

  @Get('/mainTable')
  getMainTable() {
    return this.statsService.getMainTable();
  }

  @Get('/account')
  getAccountInfo(
    @Query('bot') bot: string,
    @Query('account') account: string,
    @Query('exchange') exchange?: string,
  ) {
    return this.statsService.getAccountInfo({ bot, account, exchange });
  }

  @Get('/error/:bot')
  getErrors(@Param('bot') bot: string) {
    return this.statsService.getErrors(bot);
  }

  @Post('/updatePositionCoef')
  updatePositionCoef(@Body() body: UpdatePositionCoefRequestDto) {
    return this.statsService.updatePosCoef(body);
  }

  @Post('/updateTradeEnabled')
  updateTradeEnabled(@Body() body: UpdateTradeEnabledRequestDto) {
    return this.statsService.updateTradeEnabled(body);
  }

  @Post('/updateOffset')
  updateOffset(@Body() body: UpdateOffsetRequestDto) {
    return this.statsService.updateOffset(body);
  }

  @Post('/cancelOrder')
  cancelOrder(@Body() body: CancelOrderRequestDto) {
    return this.statsService.cancelOrder(body);
  }
}
