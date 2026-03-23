/**
 * Контроллер сигналов — NestJS-прокси к PHP signals API.
 *
 * Маршруты этого контроллера (/signals, /signals/add и т.д.) — это маршруты
 * ЛОКАЛЬНОГО NestJS бэкенда (/botctl/api/signals/...).
 * Они принимают запросы от браузера, проверяют JWT и передают данные
 * в SignalsService, который уже обращается к внешнему PHP API (SIGNALS_API_URL).
 *
 * Не путать:
 *   /botctl/api/signals  — NestJS endpoint (этот файл)
 *   SIGNALS_API_URL/sig_edit.php — PHP endpoint (signals.service.ts → safeFetch)
 */
import {
  Controller,
  Get,
  Query,
  Post,
  Body,
  Delete,
  Param,
  UseGuards,
  Request,
} from '@nestjs/common';
import { SignalsService } from '@modules/signals/signals.service';
import { JwtAuthGuard } from '@modules/jwt/jwt-auth.guard';
import {
  SigEditParams,
  SignalDeleteRequestDto,
  SignalEditRequestDto,
  SignalToggleRequestDto,
} from '@modules/signals/signals.dto';

@Controller()
@UseGuards(JwtAuthGuard)
export class SignalsController {
  constructor(private readonly signalsService: SignalsService) {}
  // Получение сигналов (сортировка, фильтры)
  @Get('signals')
  async getSignals(
    @Request() req: { user: any },
    @Query('setup') setup: string,
    @Query('sort') sort?: string,
    @Query('order') order?: 'asc' | 'desc',
    @Query('filter') filter?: string,
  ) {
    return this.signalsService.fetchSignals(
      { setup, sort, order, filter },
      req.user,
    );
  }
  @Post('signals/add')
  async addSignal(
    @Request() req: { user: any },
    @Body()
    body: {
      side: string;
      pair: string;
      multiplier?: string | number;
      signal_no: string | number;
      setup?: string | number;
    },
  ) {
    return this.signalsService.addSignal(body, req.user);
  }
  // Редактирование сигналов (multiplier, take_profit, stop_loss, limit_price, comment)
  @Post('signals/:id/edit')
  async editSignal(
    @Request() req: { user: any },
    @Param('id') id: string,
    @Body() body: SignalEditRequestDto,
  ) {
    return this.signalsService.editSignal(id, body, req.user);
  }

  // Тогглы (endlessSL, active, и т.п.)
  @Post('signals/:id/toggle')
  async toggleFlag(
    @Request() req: { user: any },
    @Param('id') id: string,
    @Body() body: SignalToggleRequestDto,
  ) {
    return this.signalsService.toggleFlag(id, body, req.user);
  }

  // Удаление
  @Delete('signals/:id/:setup')
  async deleteSignal(
    @Request() req: { user: any },
    @Param() params: SignalDeleteRequestDto,
  ) {
    return this.signalsService.deleteSignal(params, req.user);
  }
}
