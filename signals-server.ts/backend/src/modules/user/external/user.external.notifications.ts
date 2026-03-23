import { AdminNotifyService } from '@modules/events/admin-notify.service';

/** One of the supported write operations on a user. */
export type UserAction = 'create' | 'update' | 'delete';

/** Data carried to admin notification handlers on any user write. */
export interface NotifyPayload {
  actorTelegramId?: string | number;
  targetTelegramId?: string | number;
  userName?: string;
  rights?: string[];
  enabled?: number;
  meta?: Record<string, string | number | boolean | null>;
}

export const dispatchUserActionNotification = async (
  service: AdminNotifyService,
  action: UserAction,
  payload: NotifyPayload,
): Promise<void> => {
  const callbacks: Record<UserAction, (value: NotifyPayload) => Promise<void>> = {
    create: (value) => service.notifyUserCreated(value),
    update: (value) => service.notifyUserUpdated(value),
    delete: (value) => service.notifyUserDeleted(value),
  };

  await callbacks[action](payload);
};