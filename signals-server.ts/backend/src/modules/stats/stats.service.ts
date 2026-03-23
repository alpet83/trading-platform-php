import { Injectable } from '@nestjs/common';
import { safeFetch } from '../../config/env.validation';
import { Env } from '../../config/env.validation';
import {
  CancelOrderRequestDto,
  UpdateOffsetRequestDto,
  UpdatePositionCoefRequestDto,
  UpdateTradeEnabledRequestDto,
} from '@modules/stats/stats.dto';

@Injectable()
export class StatsService {
  private readonly baseUrl = Env.BOTS_STATS_URL;
  async getErrors(bot: string) {
    const res = await safeFetch('stats', this.baseUrl + '/api/last-errors' + `?bot=${bot}`, {
      method: 'GET',
    });
    return res.json();
  }
  async getAccountInfo(params: {
    bot: string;
    account: string;
    exchange?: string;
  }) {
    const query = new URLSearchParams(params).toString();
    const res = await safeFetch('stats', this.baseUrl + '/api/dashboard' + `?${query}`, {
      method: 'GET',
    });
    console.log('Request: ' + this.baseUrl + '/api/dashboard' + `?${query}`);
    return res.json();
  }
  async getMainTable() {
    const res = await safeFetch('stats', this.baseUrl + '/api', {
      method: 'GET',
    });
    return res.json();
  }

  async updatePosCoef(body: UpdatePositionCoefRequestDto) {
    const params = new URLSearchParams();
    params.append('bot', String(body.bot));
    params.append('position_coef', String(body.position_coef));
    const res = await safeFetch('stats', this.baseUrl + '/api/update-position-coef', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
      },
      body: params.toString(),
    });
    return res.json();
  }

  async updateTradeEnabled(body: UpdateTradeEnabledRequestDto) {
    const params = new URLSearchParams();
    params.append('enabled', String(body.trade_enabled));
    params.append('bot', String(body.bot));
    const res = await safeFetch('stats', this.baseUrl + '/api/update-trade-enabled', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
      },
      body: params.toString(),
    });
    return res.json();
  }

  async updateOffset(body: UpdateOffsetRequestDto) {
    const params = new URLSearchParams();
    params.append('exchange', String(body.exchange));
    params.append('account', String(body.account));
    params.append('offset', String(body.offset));
    params.append('pair_id', String(body.pair_id));
    const res = await safeFetch('stats', this.baseUrl + '/api/update-offset', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
      },
      body: params.toString(),
    });
    return res.json();
  }

  async cancelOrder(body: CancelOrderRequestDto) {
    const params = new URLSearchParams();
    console.log(body);
    params.append('order_id', String(body.order_id));
    params.append('bot', String(body.bot));
    const res = await safeFetch('stats', this.baseUrl + '/api/cancel-order', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
      },
      body: params.toString(),
    });
    return res.json();
  }
}
