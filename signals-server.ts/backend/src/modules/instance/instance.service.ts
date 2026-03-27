import {
  GatewayTimeoutException,
  Injectable,
  NotFoundException,
  ServiceUnavailableException,
} from '@nestjs/common';
import { FetchTimeoutError, safeFetch } from '../../config/env.validation';
import { Env } from '../../config/env.validation';
import {
  CancelInstanceOrderRequestDto,
  SaveInstanceHostRequestDto,
  UpdateInstanceOffsetRequestDto,
  UpdateInstancePositionCoefRequestDto,
  UpdateInstanceTradeEnabledRequestDto,
} from '@modules/instance/instance.dto';
import { InstanceHostsRepository } from '@modules/instance/instance-hosts.repository';

@Injectable()
export class InstanceService {
  constructor(private readonly instanceHostsRepository: InstanceHostsRepository) {}

  private readonly token = Env.AUTH_TOKEN;

  private withInstanceAuthHeaders(
    userId?: string | number,
    init?: import('node-fetch').RequestInit,
  ) {
    return {
      ...(init ?? {}),
      headers: {
        Authorization: 'Bearer ' + this.token,
        ...(userId !== undefined && userId !== null ? { 'X-User-Id': String(userId) } : {}),
        ...(init?.headers ?? {}),
      },
    } satisfies import('node-fetch').RequestInit;
  }

  private normalizeInstanceBaseUrl(value: unknown): string {
    const raw = String(value ?? '').trim().replace(/\/+$/, '');
    if (!raw || /^(undefined|null)$/i.test(raw)) {
      return '';
    }
    try {
      const parsed = new URL(raw);
      return parsed.toString().replace(/\/+$/, '');
    } catch {
      return '';
    }
  }

  private async requestInstanceJson(
    baseUrl: string,
    pathWithQuery: string,
    userId?: string | number,
    init?: import('node-fetch').RequestInit,
  ) {
    const url = `${baseUrl}${pathWithQuery}`;
    try {
      const res = await safeFetch('instance', url, this.withInstanceAuthHeaders(userId, init));
      return res.json();
    } catch (error: unknown) {
      if (error instanceof FetchTimeoutError) {
        // Some hosts are reachable only over http while config may contain https.
        if (url.startsWith('https://')) {
          const fallbackUrl = `http://${url.substring('https://'.length)}`;
          try {
            console.warn(`[instance] timeout for ${url}, retrying over http: ${fallbackUrl}`);
            const fallbackRes = await safeFetch(
              'instance',
              fallbackUrl,
              this.withInstanceAuthHeaders(userId, init),
            );
            return fallbackRes.json();
          } catch (fallbackError: unknown) {
            const fallbackMessage =
              fallbackError instanceof Error ? fallbackError.message : String(fallbackError);
            throw new GatewayTimeoutException(
              `[instance] upstream timeout: ${error.message}; fallback failed: ${fallbackMessage}`,
            );
          }
        }
        throw new GatewayTimeoutException(`[instance] upstream timeout: ${error.message}`);
      }
      const message = error instanceof Error ? error.message : String(error);
      throw new ServiceUnavailableException(`[instance] upstream request failed: ${message}`);
    }
  }

  private async resolveInstanceBaseUrl(hostId?: number): Promise<string> {
    if (hostId) {
      const selectedHost = await this.instanceHostsRepository.findById(hostId);
      if (!selectedHost) {
        throw new NotFoundException(`instance host id=${hostId} not found`);
      }
      const selectedUrl = this.normalizeInstanceBaseUrl(selectedHost.instance_url);
      if (selectedUrl) {
        return selectedUrl;
      }
      throw new ServiceUnavailableException(`Instance host id=${hostId} has invalid instance_url`);
    }

    const activeHost = await this.instanceHostsRepository.findActive();
    if (activeHost?.instance_url) {
      const activeUrl = this.normalizeInstanceBaseUrl(activeHost.instance_url);
      if (activeUrl) {
        return activeUrl;
      }
    }

    const fallback = this.normalizeInstanceBaseUrl(Env.INSTANCE_API_URL);
    if (fallback) {
      return fallback;
    }

    throw new ServiceUnavailableException(
      'Instance host is not configured: set valid bot_hosts.instance_url or INSTANCE_API_URL',
    );
  }

  async getHosts() {
    return this.instanceHostsRepository.list();
  }

  async createHost(body: SaveInstanceHostRequestDto) {
    return this.instanceHostsRepository.create(body);
  }

  async updateHost(hostId: number, body: SaveInstanceHostRequestDto) {
    const updated = await this.instanceHostsRepository.update(hostId, body);
    if (!updated) {
      throw new NotFoundException(`instance host id=${hostId} not found`);
    }
    return updated;
  }

  async activateHost(hostId: number) {
    const updated = await this.instanceHostsRepository.activate(hostId);
    if (!updated) {
      throw new NotFoundException(`instance host id=${hostId} not found`);
    }
    return updated;
  }

  async deleteHost(hostId: number) {
    const deleted = await this.instanceHostsRepository.delete(hostId);
    return { ok: deleted };
  }

  async getErrors(bot: string, userId?: string | number, hostId?: number) {
    const baseUrl = await this.resolveInstanceBaseUrl(hostId);
    return this.requestInstanceJson(baseUrl, '/api/last-errors' + `?bot=${bot}`, userId, {
      method: 'GET',
    });
  }

  async getAccountInfo(params: {
    bot: string;
    account: string;
    exchange?: string;
    userId?: string | number;
    hostId?: number;
  }) {
    const { hostId, userId, ...rest } = params;
    const baseUrl = await this.resolveInstanceBaseUrl(hostId);
    const queryParams = new URLSearchParams();
    for (const [key, value] of Object.entries(rest)) {
      if (value !== undefined && value !== null) {
        queryParams.append(key, String(value));
      }
    }
    const query = queryParams.toString();
    const result = await this.requestInstanceJson(baseUrl, '/api/dashboard' + `?${query}`, userId, {
      method: 'GET',
    });
    console.log('Request: ' + baseUrl + '/api/dashboard' + `?${query}`);
    return result;
  }

  async getMainTable(userId?: string | number, hostId?: number) {
    const baseUrl = await this.resolveInstanceBaseUrl(hostId);
    return this.requestInstanceJson(baseUrl, '/api', userId, {
      method: 'GET',
    });
  }

  async updatePosCoef(body: UpdateInstancePositionCoefRequestDto, userId?: string | number) {
    const baseUrl = await this.resolveInstanceBaseUrl(body.hostId);
    const params = new URLSearchParams();
    params.append('bot', String(body.bot));
    params.append('position_coef', String(body.position_coef));
    return this.requestInstanceJson(baseUrl, '/api/update-position-coef', userId, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
      },
      body: params.toString(),
    });
  }

  async updateTradeEnabled(body: UpdateInstanceTradeEnabledRequestDto, userId?: string | number) {
    const baseUrl = await this.resolveInstanceBaseUrl(body.hostId);
    const params = new URLSearchParams();
    params.append('enabled', String(body.trade_enabled));
    params.append('bot', String(body.bot));
    return this.requestInstanceJson(baseUrl, '/api/update-trade-enabled', userId, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
      },
      body: params.toString(),
    });
  }

  async updateOffset(body: UpdateInstanceOffsetRequestDto, userId?: string | number) {
    const baseUrl = await this.resolveInstanceBaseUrl(body.hostId);
    const params = new URLSearchParams();
    params.append('exchange', String(body.exchange));
    params.append('account', String(body.account));
    params.append('offset', String(body.offset));
    params.append('pair_id', String(body.pair_id));
    return this.requestInstanceJson(baseUrl, '/api/update-offset', userId, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
      },
      body: params.toString(),
    });
  }

  async cancelOrder(body: CancelInstanceOrderRequestDto, userId?: string | number) {
    const baseUrl = await this.resolveInstanceBaseUrl(body.hostId);
    const params = new URLSearchParams();
    console.log(body);
    params.append('order_id', String(body.order_id));
    params.append('bot', String(body.bot));
    return this.requestInstanceJson(baseUrl, '/api/cancel-order', userId, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
      },
      body: params.toString(),
    });
  }
}
