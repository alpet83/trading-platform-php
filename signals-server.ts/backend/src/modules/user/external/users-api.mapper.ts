import { TradingUser } from '@modules/user/trading/trading-users.types';

/** Outcome of a create/update/delete operation. */
export type ActionReason =
  | 'created'
  | 'updated'
  | 'deleted'
  | 'already_exists'
  | 'not_found';

/** User shape returned to the API consumer. */
export interface UserRecord {
  id: number;
  user_name: string;
  rights: string[];
  enabled: number;
  base_setup: number;
}

/** Standardized response envelope for all write operations. */
export interface ActionResult {
  ok: boolean;
  reason: ActionReason;
  id: number;
  user: UserRecord | null;
}

export const toUserRecord = (user: TradingUser): UserRecord => ({
  id: user.id,
  user_name: user.user_name,
  rights: [...user.rights],
  enabled: user.enabled,
  base_setup: user.base_setup,
});

export const toUserRecords = (users: TradingUser[]): UserRecord[] =>
  users.map(toUserRecord);

export const buildActionResult = (
  reason: ActionReason,
  id: number,
  user: TradingUser | null,
): ActionResult => ({
  ok: reason !== 'not_found',
  reason,
  id,
  user: user ? toUserRecord(user) : null,
});