import {
  Body,
  Request,
  Controller,
  Get,
  Post,
  UseGuards,
} from '@nestjs/common';
import { UserService } from '@modules/user/user.service';
import { TelegramAuthDto } from '@modules/user/user.dto';
import { JwtAuthGuard } from '@modules/jwt/jwt-auth.guard';

@Controller('/user')
export class UserController {
  constructor(private readonly service: UserService) {}

  @Post('telegram')
  async telegramLogin(@Body() body: TelegramAuthDto) {
    const botToken = process.env.TELEGRAM_BOT_TOKEN;
    return this.service.loginWithTelegram(body, botToken);
  }
  @UseGuards(JwtAuthGuard)
  @Get('telegram')
  async getUser(@Request() req: { user: any }) {
    return this.service.getUser(req.user);
  }
}
