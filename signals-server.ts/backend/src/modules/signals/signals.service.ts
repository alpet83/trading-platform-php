import { Injectable } from '@nestjs/common';
import { safeFetch } from '../../config/env.validation';
import { Env } from '../../config/env.validation';
import {
  SignalDeleteRequestDto,
  SignalEditRequestDto,
  SignalToggleRequestDto,
} from '@modules/signals/signals.dto';

/**
 * АРХИТЕКТУРА: два независимых бэкенда
 *
 * 1. NestJS (этот код) — «локальный бэкенд»
 *    Маршруты: /botctl/api/signals/*
 *    Роль: авторизация (JWT), проксирование запросов к PHP API.
 *    НЕ хранит и НЕ обрабатывает сигналы сам.
 *
 * 2. PHP signals API — «внешний бэкенд»
 *    URL: SIGNALS_API_URL (например https://myserver.com/signals-api/)
 *    Точка входа: sig_edit.php?format=json
 *    Роль: весь CRUD сигналов. Работает независимо от NestJS.
 *
 * Поток запроса:
 *   Браузер → /botctl/api/signals?setup=9
 *     → NestJS SignalsController (проверяет JWT)
 *       → SignalsService.fetchSignals()
 *         → safeFetch → https://myserver.com/signals-api/sig_edit.php?format=json&setup=9
 *
 * ⚠️  SIGNALS_API_URL — это НЕ путь NestJS, это отдельный PHP-сервер.
 *     Не добавлять туда /botctl, /api или любой другой NestJS-префикс.
 *
 * ⚠️  AUTH_TOKEN (из .env.node.docker) должен совпадать с константой
 *     FRONTEND_TOKEN в PHP конфиге сервера (/usr/local/etc/php/db_config.php
 *     или аналогичном). Если токены расходятся — PHP вернёт 401 Unauthorized.
 *     Проверить: api_helper.php → check_auth() → "Bearer {FRONTEND_TOKEN}"
 */

@Injectable()
export class SignalsService {
  private readonly token = Env.AUTH_TOKEN;

  // URL PHP-бэкенда. Trailing slash удаляется чтобы избежать двойного //
  // Пример: https://myserver.com/signals-api/sig_edit.php?format=json
  private readonly baseUrl = Env.SIGNALS_API_URL.replace(/\/+$/, '') + '/sig_edit.php?format=json';

  async fetchSignals(
    {
      setup,
      sort,
      order,
      filter,
    }: {
      setup: string;
      sort?: string;
      order?: string;
      filter?: string;
    },
    user: any,
  ) {
    let url = `${this.baseUrl}&setup=${setup}`;
    if (sort) url += `&sort=${sort}`;
    if (order) url += `&order=${order}`;
    if (filter) url += `&filter=${filter}`;

    const res = await safeFetch('signals', url, {
      headers: {
        Authorization: 'Bearer ' + this.token,
        'X-User-Id': user.telegramId,
      },
    });
    return res.json();
  }

  async editSignal(id: string, body: SignalEditRequestDto, user: any) {
    let query = '';
    switch (body.field) {
      case 'multiplier':
        query = `&sig_id=${id}&amount=${body.value}`;
        break;
      case 'take_profit':
        query = `&edit_tp=${id}&price=${body.value}`;
        break;
      case 'stop_loss':
        query = `&edit_sl=${id}&price=${body.value}`;
        break;
      case 'limit_price':
        query = `&edit_lp=${id}&price=${body.value}`;
        break;
      case 'comment':
        query = `&edit_comment=${id}&text=${body.text ?? body.value}`;
        break;
      default:
        throw new Error('Unsupported field');
    }
    const res = await safeFetch('signals', this.baseUrl + query + `&setup=${body.setup}`, {
      method: 'PATCH',
      headers: {
        Authorization: 'Bearer ' + this.token,
        'X-User-Id': user.telegramId,
      },
    });
    console.log(this.baseUrl + query + `&setup=${body.setup}`);
    return res.json();
  }

  async toggleFlag(id: string, body: SignalToggleRequestDto, user: any) {
    const res = await safeFetch(
      'signals',
      this.baseUrl + `&${body.flag}=${id}&setup=${body.setup}`,
      {
        method: 'PUT',
        headers: {
          Authorization: 'Bearer ' + this.token,
          'X-User-Id': user.telegramId,
        },
      },
    );
    return res.json();
  }

  async deleteSignal(data: SignalDeleteRequestDto, user: any) {
    const res = await safeFetch(
      'signals',
      this.baseUrl + `&delete=${data.id}&setup=${data.setup}`,
      {
        method: 'DELETE',
        headers: {
          Authorization: 'Bearer ' + this.token,
          'X-User-Id': user.telegramId,
        },
      },
    );
    return res.json();
  }
  async addSignal(
    body: {
      side: string;
      pair: string;
      multiplier?: string | number;
      signal_no: string | number;
      setup?: string | number;
    },
    user: any,
  ) {
    // Формируем форму
    const params = new URLSearchParams();

    // setup по умолчанию = 9, если не передан
    params.append('setup', String(body.setup ?? 9));
    params.append('side', body.side);
    params.append('pair', body.pair.toUpperCase());
    params.append('signal_no', String(body.signal_no));
    if (body.multiplier) {
      params.append('multiplier', String(body.multiplier));
    }

    const res = await safeFetch('signals', this.baseUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        Authorization: 'Bearer ' + this.token,
        'X-User-Id': user.telegramId,
      },
      body: params.toString(),
    });
    console.log(res);
    if (!res.ok) {
      throw new Error(`Failed to add signal: ${res.statusText}`);
    }

    return res.json();
  }
}
