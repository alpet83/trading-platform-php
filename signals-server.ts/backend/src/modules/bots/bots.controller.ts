import {
  Body,
  Controller,
  Delete,
  Get,
  Param,
  Post,
  Request,
  UseGuards,
} from '@nestjs/common';
import { BotsService } from '@modules/bots/bots.service';
import { CreateBotDTO, UpdateBotDTO } from '@modules/bots/bots.dto';
import { JwtAuthGuard } from '@modules/jwt/jwt-auth.guard';

@Controller()
@UseGuards(JwtAuthGuard)
export class BotsController {
  constructor(private service: BotsService) {}
  @Get('bots')
  getBots(@Request() req: { user: any }) {
    return this.service.getBots(req.user);
  }

  @Post('bots/create')
  createBot(@Body() body: CreateBotDTO, @Request() req: { user: any }) {
    return this.service.createBot(body, req.user);
  }
  @Post('bots/update')
  updateBot(@Body() body: UpdateBotDTO, @Request() req: { user: any }) {
    return this.service.updateBot(body, req.user);
  }

  @Delete('bots/:name')
  deleteBot(@Param('name') name: string, @Request() req: { user: any }) {
    return this.service.deleteBot(name, req.user);
  }
}
