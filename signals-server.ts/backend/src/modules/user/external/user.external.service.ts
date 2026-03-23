import { Injectable } from '@nestjs/common';
import { Env } from '../../../config/env.validation';
import { AdminNotifyService } from '@modules/events/admin-notify.service';
import { TradingUsersRepository } from '@modules/user/trading/trading-users.repository';
import { CreateUserDTO, UpdateUserDTO } from './user.external.dto';
import {
  IUserExternalService,
  UserListResult,
  DBActionResult,
  AdminCheckResult,
} from './user.external.interface';
import { UserExternalDbService } from './user.external.db.service';
import { UserExternalPhpService } from './user.external.php.service';

/**
 * Facade: delegates to UserExternalDbService (ts) or UserExternalPhpService (php)
 * based on USERS_API_SOURCE env variable.
 * Kept as a named class so existing consumers (UserService, module) need no changes.
 */
@Injectable()
export class UserExternalService implements IUserExternalService {
  private readonly impl: IUserExternalService;

  constructor(
    adminNotifyService: AdminNotifyService,
    tradingUsersRepository: TradingUsersRepository,
  ) {
    this.impl =
      Env.USERS_API_SOURCE === 'ts'
        ? new UserExternalDbService(adminNotifyService, tradingUsersRepository)
        : new UserExternalPhpService(adminNotifyService);
  }

  getUsers(user: any): UserListResult {
    return this.impl.getUsers(user);
  }

  createUser(proto: CreateUserDTO, user: any): DBActionResult {
    return this.impl.createUser(proto, user);
  }

  updateUser(user: any, body: UpdateUserDTO): DBActionResult {
    return this.impl.updateUser(user, body);
  }

  deleteUser(user: any, id: string | number): DBActionResult {
    return this.impl.deleteUser(user, id);
  }

  isAdmin(user: any): AdminCheckResult {
    return this.impl.isAdmin(user);
  }
}
