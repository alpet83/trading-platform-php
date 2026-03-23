import { Controller, Post } from '@nestjs/common';

@Controller('telegram')
export class TelegramController {
  @Post('login')
  async getTokenAndRegister() {
    console.log(123);
  }
}
