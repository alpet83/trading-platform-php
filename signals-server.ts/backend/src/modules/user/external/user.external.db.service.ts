import { Injectable } from '@nestjs/common';
import { AdminNotifyService } from '@modules/events/admin-notify.service';
import { TradingUsersRepository } from '@modules/user/trading/trading-users.repository';
import { TradingUser } from '@modules/user/trading/trading-users.types';
import { CreateUserDTO, UpdateUserDTO } from './user.external.dto';
import {
  IUserExternalService,
  UserListResult,
  DBActionResult,
  AdminCheckResult,
  SetupBaseGroupsResult,
  UserLookupResult,
} from './user.external.interface';
import { normalizeMutationInput, toUserId } from './user.external.input';
import {
  dispatchUserActionNotification,
  UserAction,
} from './user.external.notifications';
import { normalizeUsersApiError } from './users-api.error';
import {
  buildActionResult,
  toUserRecords,
  ActionReason,
} from './users-api.mapper';

@Injectable()
export class UserExternalDbService implements IUserExternalService {
  constructor(
    private readonly adminNotifyService: AdminNotifyService,
    private readonly tradingUsersRepository: TradingUsersRepository,
  ) {}

  async getUsers(_user: any): UserListResult {
    const users = await this.tradingUsersRepository.findAll();
    return toUserRecords(users);
  }

  async getByTelegramId(telegramId: number): UserLookupResult {
    return this.tradingUsersRepository.findByChatId(Number(telegramId));
  }

  async createUser(proto: CreateUserDTO, user: any): DBActionResult {
    return this.run(async () => {
      const payload = normalizeMutationInput(proto);
      const existingUser = await this.findExistingUser(payload.id, payload.user_name);

      if (existingUser) {
        return this.result('already_exists', existingUser.id, existingUser);
      }

      const result = await this.tradingUsersRepository.create(payload);

      await this.notify('create', user, result, { source: 'user.external.createUser.ts' });

      return this.result('created', result.id, result);
    });
  }

  async updateUser(user: any, body: UpdateUserDTO): DBActionResult {
    return this.run(async () => {
      const payload = normalizeMutationInput(body);
      const currentUser = await this.tradingUsersRepository.findByChatId(payload.id);

      if (!currentUser) {
        return this.result('not_found', payload.id, null);
      }

      const existingByUserName = await this.tradingUsersRepository.findByUserName(
        payload.user_name,
      );

      if (existingByUserName && existingByUserName.id !== payload.id) {
        return this.result('already_exists', existingByUserName.id, existingByUserName);
      }

      const result = await this.tradingUsersRepository.update(payload);

      if (!result) {
        return this.result('not_found', payload.id, null);
      }

      await this.notify('update', user, result, { source: 'user.external.updateUser.ts' });

      return this.result('updated', result.id, result);
    });
  }

  async deleteUser(user: any, id: string | number): DBActionResult {
    return this.run(async () => {
      const userId = toUserId(id);
      const existingUser = await this.tradingUsersRepository.findByChatId(userId);

      if (!existingUser) {
        return this.result('not_found', userId, null);
      }

      const deleted = await this.tradingUsersRepository.deleteByChatId(userId);

      if (deleted) {
        await this.notify('delete', user, existingUser, {
          source: 'user.external.deleteUser.ts',
        });
      }

      return this.result(
        deleted ? 'deleted' : 'not_found',
        userId,
        deleted ? existingUser : null,
      );
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
    return this.tradingUsersRepository.findSetupBaseGroups();
  }

  private async run<T>(operation: () => Promise<T>): Promise<T> {
    try {
      return await operation();
    } catch (error) {
      normalizeUsersApiError(error);
    }
  }

  private result(
    reason: ActionReason,
    id: number,
    user: TradingUser | null,
  ) {
    return buildActionResult(reason, id, user);
  }

  private async findExistingUser(
    id: number,
    userName: string,
  ): Promise<TradingUser | null> {
    const existingById = await this.tradingUsersRepository.findByChatId(id);

    if (existingById) {
      return existingById;
    }

    return this.tradingUsersRepository.findByUserName(userName);
  }

  private async notify(
    action: UserAction,
    actorUser: any,
    targetUser: TradingUser,
    meta: Record<string, string | number | boolean | null>,
  ): Promise<void> {
    await dispatchUserActionNotification(this.adminNotifyService, action, {
      actorTelegramId: actorUser?.telegramId,
      targetTelegramId: String(targetUser.id),
      userName: targetUser.user_name,
      rights: targetUser.rights,
      enabled: targetUser.enabled,
      meta,
    });
  }
}
