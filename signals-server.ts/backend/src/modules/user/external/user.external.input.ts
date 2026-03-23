import { CreateUserDTO, UpdateUserDTO } from './user.external.dto';
import {
  EnabledFlag,
  TradingUserRight,
  VALID_TRADING_USER_RIGHTS,
} from '@modules/user/trading/trading-users.types';

export interface NormalizedMutationInput {
  id: number;
  user_name: string;
  rights: TradingUserRight[];
  enabled: EnabledFlag;
}

export function toUserId(value: string | number): number {
  const parsed = Number(value);

  if (!Number.isInteger(parsed) || parsed <= 0) {
    throw new Error(`Invalid user id: ${value}`);
  }

  return parsed;
}

export function toUserName(value: unknown): string {
  const normalized = String(value || '').trim();

  if (!normalized) {
    throw new Error('Trading user user_name must be a non-empty string');
  }

  return normalized;
}

export function toEnabledFlag(value: unknown): EnabledFlag {
  return Number(value) === 1 ? 1 : 0;
}

export function normalizeRights(rights: string[]): TradingUserRight[] {
  const normalized = rights
    .map((item) => item.trim())
    .filter((item): item is TradingUserRight =>
      VALID_TRADING_USER_RIGHTS.includes(item as TradingUserRight),
    );

  if (normalized.length !== rights.length) {
    throw new Error('Invalid trading user rights payload');
  }

  return normalized;
}

export function normalizeMutationInput(
  proto: CreateUserDTO | UpdateUserDTO,
): NormalizedMutationInput {
  return {
    id: toUserId(proto.id),
    user_name: toUserName(proto.user_name),
    rights: normalizeRights(proto.rights),
    enabled: toEnabledFlag(proto.enabled),
  };
}
