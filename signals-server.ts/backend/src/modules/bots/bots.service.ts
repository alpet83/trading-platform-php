import { Injectable } from '@nestjs/common';
import { Env, backendFetch } from '../../config/env.validation';
import { CreateBotDTO, UpdateBotDTO } from '@modules/bots/bots.dto';

@Injectable()
export class BotsService {
  private readonly token = Env.AUTH_TOKEN;
  async getBots(user: any) {
    const data = await backendFetch('/bots', {
      method: 'GET',
      headers: {
        Authorization: 'Bearer ' + this.token,
        'X-User-Id': user.telegramId,
      },
    });
    return await data.json();
  }

  async createBot(body: CreateBotDTO, user: any) {
    const params = new URLSearchParams();

    // РћР±СЏР·Р°С‚РµР»СЊРЅС‹Рµ РїРѕР»СЏ
    params.append('bot_name', body.bot_name);
    params.append('account_id', String(body.account_id));
    params.append('config[exchange]', body.config.exchange);
    params.append('config[trade_enabled]', body.config.trade_enabled);
    params.append('config[position_coef]', body.config.position_coef);
    params.append('config[monitor_enabled]', body.config.monitor_enabled);
    params.append('config[min_order_cost]', body.config.min_order_cost);
    params.append('config[max_order_cost]', body.config.max_order_cost);
    params.append('config[max_limit_distance]', body.config.max_limit_distance);
    params.append('config[signals_setup]', body.config.signals_setup);
    params.append('config[report_color]', body.config.report_color);
    params.append('config[debug_pair]', body.config.debug_pair);

    // РћРїС†РёРѕРЅР°Р»СЊРЅС‹Рµ РїРѕР»СЏ
    const optionalKeys: (keyof CreateBotDTO['config'])[] = [
      'api_key_name',
      'api_secret_name',
      'api_secret_sep',
      'api_secret_sep_',
      'max_pos_cost',
      'max_pos_amount',
      'shorts_mult',
      'last_nonce',
      'limit_base_ttl',
      'order_timeout',
    ];

    optionalKeys.forEach((key) => {
      if (body.config[key] !== undefined && body.config[key] !== null) {
        params.append(`config[${key}]`, body.config[key]!);
      }
    });

    const data = await backendFetch('/bots/create', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        Authorization: 'Bearer ' + this.token,
        'X-User-Id': user.telegramId,
      },
      body: params.toString(),
    });

    return await data.json();
  }

  async updateBot(body: UpdateBotDTO, user: any) {
    const params = new URLSearchParams();

    // РћР±СЏР·Р°С‚РµР»СЊРЅС‹Рµ РїРѕР»СЏ
    params.append('applicant', body.applicant);
    params.append('config[exchange]', body.config.exchange);
    params.append('config[trade_enabled]', body.config.trade_enabled);
    params.append('config[position_coef]', body.config.position_coef);
    params.append('config[monitor_enabled]', body.config.monitor_enabled);
    params.append('config[min_order_cost]', body.config.min_order_cost);
    params.append('config[max_order_cost]', body.config.max_order_cost);
    params.append('config[max_limit_distance]', body.config.max_limit_distance);
    params.append('config[signals_setup]', body.config.signals_setup);
    params.append('config[report_color]', body.config.report_color);
    params.append('config[debug_pair]', body.config.debug_pair);

    const data = await backendFetch('/bots/update', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        Authorization: 'Bearer ' + this.token,
        'X-User-Id': user.telegramId,
      },
      body: params.toString(),
    });

    return await data.json();
  }

  async deleteBot(name: string, user: any) {
    console.log('ID to delete: ', name);
    const params = new URLSearchParams();
    params.append('applicant', String(name).trim());
    const data = await backendFetch('/bots/delete', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'X-User-Id': user.telegramId,
        Authorization: 'Bearer ' + this.token,
      },
      body: params.toString(),
    });
    console.log(data);
    return data;
  }
}
