import { Injectable, Logger } from '@nestjs/common';
import { hostname } from 'os';
import { AuditLogService } from '../../common/logging/audit-log.service';
import { TradingEventsRepository } from './trading-events.repository';
import { TradingUsersRepository } from '../user/trading/trading-users.repository';

interface AdminNotifyPayload {
  actorTelegramId?: string | number;
  targetTelegramId?: string | number;
  userName?: string;
  rights?: string[];
  enabled?: number;
  meta?: Record<string, string | number | boolean | null>;
}

@Injectable()
export class AdminNotifyService {
  private readonly logger = new Logger(AdminNotifyService.name);

  constructor(
    private readonly repository: TradingEventsRepository,
    private readonly auditLogService: AuditLogService,
    private readonly tradingUsersRepository: TradingUsersRepository,
  ) {}

  async notifyLoginSuccess(payload: AdminNotifyPayload): Promise<void> {
    await this.emit('LOGIN', 'Telegram login success', payload);
  }

  async notifyUserCreated(payload: AdminNotifyPayload): Promise<void> {
    await this.emit('REPORT', 'User created', payload);
  }

  async notifyUserUpdated(payload: AdminNotifyPayload): Promise<void> {
    await this.emit('REPORT', 'User updated', payload);
  }

  async notifyUserDeleted(payload: AdminNotifyPayload): Promise<void> {
    await this.emit('REPORT', 'User deleted', payload);
  }

  private async emit(
    tag: string,
    title: string,
    payload: AdminNotifyPayload,
  ): Promise<void> {
    if (!this.isEnabled()) {
      return;
    }

    const body = {
      actorTelegramId: payload.actorTelegramId ?? null,
      targetTelegramId: payload.targetTelegramId ?? null,
      userName: payload.userName ?? null,
      rights: payload.rights ?? null,
      enabled: payload.enabled ?? null,
      meta: payload.meta ?? null,
    };

    const hostName = process.env.TRADING_EVENTS_HOST || hostname();

    try {
      const adminUsers = await this.tradingUsersRepository.getAdminUsers();

      if (adminUsers.length === 0) {
        return;
      }

      const hostId = await this.repository.resolveHostId(hostName);
      const eventText = `${title}: ${JSON.stringify(body)}`;

      for (const adminUser of adminUsers) {
        const record = await this.repository.insertEvent({
          tag,
          event: eventText,
          value: 0,
          flags: 0,
          chatId: adminUser.id,
          hostId,
        });

        await this.auditLogService.logRequest({
          ts: new Date().toISOString(),
          requestId: `event-${record.id || Date.now()}`,
          route: 'events.insert',
          statusCode: 200,
          durationMs: 0,
          body: {
            tag,
            title,
            chatId: adminUser.id,
            hostId,
            eventId: record.id,
          },
        });
      }
    } catch (error) {
      const reason = error instanceof Error ? error.message : String(error);
      this.logger.error(`Failed to emit admin notification: ${reason}`);

      await this.auditLogService.logError({
        ts: new Date().toISOString(),
        requestId: `event-failed-${Date.now()}`,
        route: 'events.insert',
        statusCode: 500,
        durationMs: 0,
        body: {
          tag,
          title,
          error: reason,
          payload: body,
        },
      });
    }
  }

  private isEnabled(): boolean {
    const raw = String(process.env.TRADING_EVENTS_ENABLED || '1').toLowerCase();
    return raw !== '0' && raw !== 'false' && raw !== 'off';
  }
}
