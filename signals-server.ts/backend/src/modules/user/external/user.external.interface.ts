import { CreateUserDTO, UpdateUserDTO } from './user.external.dto';
import { ActionResult } from './users-api.mapper';

export const USER_EXTERNAL_SERVICE = Symbol('USER_EXTERNAL_SERVICE');

export type UserListResult = Promise<any[]>;
export type DBActionResult = Promise<ActionResult>;
export type AdminCheckResult = Promise<boolean>;

export interface IUserExternalService {
  getUsers(user: any): UserListResult;
  createUser(proto: CreateUserDTO, user: any): DBActionResult;
  updateUser(user: any, body: UpdateUserDTO): DBActionResult;
  deleteUser(user: any, id: string | number): DBActionResult;
  isAdmin(user: any): AdminCheckResult;
}
