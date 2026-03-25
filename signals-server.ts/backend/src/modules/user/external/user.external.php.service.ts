import { Injectable, NotImplementedException } from '@nestjs/common';
import { Env } from '../../../config/env.validation';
import { AdminNotifyService } from '@modules/events/admin-notify.service';
import { CreateUserDTO, UpdateUserDTO } from './user.external.dto';
import {
  IUserExternalService,
  UserListResult,
  DBActionResult,
  AdminCheckResult,
  SetupBaseGroupsResult,
} from './user.external.interface';
import { normalizeRights } from './user.external.input';
import { UserExternalPhpClient } from './user.external.php-client';
import { dispatchUserActionNotification } from './user.external.notifications';

@Injectable()
export class UserExternalPhpService implements IUserExternalService {
  private readonly phpClient = new UserExternalPhpClient(
    Env.SIGNALS_API_URL,
    Env.AUTH_TOKEN,
  );

  constructor(private readonly adminNotifyService: AdminNotifyService) {}

  async getUsers(user: any): UserListResult {
    return this.phpClient.callJson('/api/users/', 'GET', String(user.telegramId));
  }

  async createUser(proto: CreateUserDTO, user: any): DBActionResult {
    return this.phpClient.callMutation({
      endpoint: '/api/users/create',
      actionName: 'users/create',
      userId: String(proto.id),
      payload: proto,
      onSuccess: async (status) => {
        await dispatchUserActionNotification(this.adminNotifyService, 'create', {
          actorTelegramId: user?.telegramId,
          targetTelegramId: String(proto.id),
          userName: String(proto.user_name),
          rights: normalizeRights(proto.rights),
          enabled: Number(proto.enabled) === 1 ? 1 : 0,
          meta: { source: 'user.external.createUser', status },
        });
      },
    });
  }

  async updateUser(user: any, body: UpdateUserDTO): DBActionResult {
    return this.phpClient.callMutation({
      endpoint: '/api/users/update',
      actionName: 'users/update',
      userId: String(user.telegramId),
      payload: body,
      onSuccess: async (status) => {
        await dispatchUserActionNotification(this.adminNotifyService, 'update', {
          actorTelegramId: user?.telegramId,
          targetTelegramId: String(body.id),
          userName: String(body.user_name),
          rights: normalizeRights(body.rights),
          enabled: Number(body.enabled) === 1 ? 1 : 0,
          meta: { source: 'user.external.updateUser', status },
        });
      },
    });
  }

  async deleteUser(user: any, id: string | number): DBActionResult {
    return this.phpClient.callMutation({
      endpoint: '/api/users/delete',
      actionName: 'users/delete',
      userId: String(user.telegramId),
      payload: { id: String(id).trim() },
      responseType: 'raw',
      onSuccess: async (status) => {
        await dispatchUserActionNotification(this.adminNotifyService, 'delete', {
          actorTelegramId: user?.telegramId,
          targetTelegramId: String(id),
          meta: { source: 'user.external.deleteUser', status },
        });
      },
    });
  }

  async isAdmin(user: any): AdminCheckResult {
    const users = await this.getUsers(user);

    if (!users) {
      return false;
    }

    const current = users.find((item: { id: number | string }) => {
      return item.id == user.telegramId;
    });

    return Boolean(current?.rights?.includes('admin'));
  }

  async getSetupBaseGroups(_user: any): SetupBaseGroupsResult {
    throw new NotImplementedException(
      'Setup-base groups are available only for USERS_API_SOURCE=ts',
    );
  }
}
