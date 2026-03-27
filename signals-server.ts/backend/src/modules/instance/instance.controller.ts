import {
  BadRequestException,
  Controller,
  Get,
  Query,
  Post,
  Body,
  Param,
  Request,
  UseGuards,
  Delete,
} from '@nestjs/common';
import { InstanceService } from '@modules/instance/instance.service';
import {
  CancelInstanceOrderRequestDto,
  SaveInstanceHostRequestDto,
  UpdateInstanceOffsetRequestDto,
  UpdateInstancePositionCoefRequestDto,
  UpdateInstanceTradeEnabledRequestDto,
} from '@modules/instance/instance.dto';
import { JwtAuthGuard } from '@modules/jwt/jwt-auth.guard';

@Controller('/instance')
@UseGuards(JwtAuthGuard)
export class InstanceController {
  constructor(private readonly instanceService: InstanceService) {}

  private parseHostId(hostId?: string): number | undefined {
    if (!hostId) {
      return undefined;
    }
    const parsed = Number(hostId);
    if (!Number.isInteger(parsed) || parsed <= 0) {
      throw new BadRequestException('hostId must be a positive integer');
    }
    return parsed;
  }

  @Get('/mainTable')
  getMainTable(@Query('hostId') hostId?: string, @Request() req?: { user?: any }) {
    return this.instanceService.getMainTable(req?.user?.telegramId, this.parseHostId(hostId));
  }

  @Get('/account')
  getAccountInfo(
    @Query('bot') bot: string,
    @Query('account') account: string,
    @Query('exchange') exchange?: string,
    @Query('hostId') hostId?: string,
    @Request() req?: { user?: any },
  ) {
    return this.instanceService.getAccountInfo({
      bot,
      account,
      exchange,
      userId: req?.user?.telegramId,
      hostId: this.parseHostId(hostId),
    });
  }

  @Get('/error/:bot')
  getErrors(
    @Param('bot') bot: string,
    @Query('hostId') hostId?: string,
    @Request() req?: { user?: any },
  ) {
    return this.instanceService.getErrors(bot, req?.user?.telegramId, this.parseHostId(hostId));
  }

  @Get('/hosts')
  getHosts() {
    return this.instanceService.getHosts();
  }

  @Post('/hosts')
  createHost(@Body() body: SaveInstanceHostRequestDto) {
    return this.instanceService.createHost(body);
  }

  @Post('/hosts/:hostId')
  updateHost(@Param('hostId') hostId: string, @Body() body: SaveInstanceHostRequestDto) {
    return this.instanceService.updateHost(this.parseHostId(hostId), body);
  }

  @Post('/hosts/:hostId/activate')
  activateHost(@Param('hostId') hostId: string) {
    return this.instanceService.activateHost(this.parseHostId(hostId));
  }

  @Delete('/hosts/:hostId')
  deleteHost(@Param('hostId') hostId: string) {
    return this.instanceService.deleteHost(this.parseHostId(hostId));
  }

  @Post('/updatePositionCoef')
  updatePositionCoef(
    @Body() body: UpdateInstancePositionCoefRequestDto,
    @Request() req?: { user?: any },
  ) {
    return this.instanceService.updatePosCoef(body, req?.user?.telegramId);
  }

  @Post('/updateTradeEnabled')
  updateTradeEnabled(
    @Body() body: UpdateInstanceTradeEnabledRequestDto,
    @Request() req?: { user?: any },
  ) {
    return this.instanceService.updateTradeEnabled(body, req?.user?.telegramId);
  }

  @Post('/updateOffset')
  updateOffset(@Body() body: UpdateInstanceOffsetRequestDto, @Request() req?: { user?: any }) {
    return this.instanceService.updateOffset(body, req?.user?.telegramId);
  }

  @Post('/cancelOrder')
  cancelOrder(@Body() body: CancelInstanceOrderRequestDto, @Request() req?: { user?: any }) {
    return this.instanceService.cancelOrder(body, req?.user?.telegramId);
  }
}
